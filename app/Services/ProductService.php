<?php

namespace App\Services;

use App\Models\EngageOrganizationLocation;
use App\Models\EngageProduct;
use App\Models\EngageProductRentalCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductService
{
    private const EAGER = ['categories', 'rentals', 'rentals.serviceCategory', 'defaultRental', 'defaultRental.serviceCategory', 'amenities', 'features'];

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = EngageProduct::query();

        if (! empty($filters['engage_organization_location_id'])) {
            $query->byLocation($filters['engage_organization_location_id']);
        }

        if (! empty($filters['product_type'])) {
            $query->where('product_type', $filters['product_type']);
        }

        if (array_key_exists('is_rental', $filters)) {
            if ((bool) $filters['is_rental']) {
                $query->whereNotNull('product_rental_id');
            } else {
                $query->whereNull('product_rental_id');
            }
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', '!=', 'archived');
        }

        if (! empty($filters['engage_sync_status'])) {
            $query->where('engage_sync_status', $filters['engage_sync_status']);
        }

        if (! empty($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (! empty($filters['category_id'])) {
            $query->whereHas('categories', fn (Builder $q) => $q->where('engage_categories.id', $filters['category_id']));
        }

        // Rental-side counterpart of category_id above — Manage Service's
        // own category filter. Same indirection listServices() already uses:
        // product_rentals.service_category_id stores the raw GHL category
        // id, not this table's local ULID, so it's resolved first.
        if (! empty($filters['service_category_id'])) {
            $ghlCategoryId = EngageProductRentalCategory::where('engage_organization_location_id', $filters['engage_organization_location_id'] ?? null)
                ->where('id', $filters['service_category_id'])
                ->value('ghl_category_id');

            $query->whereHas('rentals', fn (Builder $q) => $q->where('service_category_id', $ghlCategoryId ?? '__none__'));
        }

        return $query->with(self::EAGER)
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): EngageProduct
    {
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants']);

        if (empty($data['sku'])) {
            $data['sku'] = $this->generateUniqueSku($data['engage_organization_location_id'], $data['name'] ?? '');
        }

        $product = EngageProduct::create($data);

        if (! empty($categoryIds)) {
            $product->categories()->sync($categoryIds);
        }

        return $product->load(self::EAGER);
    }

    public function update(EngageProduct $product, array $data): EngageProduct
    {
        $categoryIds = $data['category_ids'] ?? null;
        $amenityIds = $data['amenity_ids'] ?? null;
        $featureIds = $data['feature_ids'] ?? null;
        // Base-rental pricing fields (Manage Service's Pricing section) —
        // pulled out the same way as amenity/feature ids above, since they
        // live on EngageProductRental, not EngageProduct itself.
        $rentalData = array_intersect_key($data, array_flip(['listing_price', 'service_duration_unit', 'security_deposit_amount']));
        unset(
            $data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants'],
            $data['listing_price'], $data['service_duration_unit'], $data['security_deposit_amount'],
        );

        $product->update($data);

        if ($categoryIds !== null) {
            $product->categories()->sync($categoryIds);
        }

        // Amenities/features are a Services-module concept (see service_amenities/
        // service_features) — syncing is harmless to send for a non-rental product,
        // but the goods form never sends these keys, so this only ever fires from
        // the Manage Service edit form in practice.
        if ($amenityIds !== null) {
            $product->amenities()->sync($amenityIds);
        }

        if ($featureIds !== null) {
            $product->features()->sync($featureIds);
        }

        // Same reasoning — only a rental has a base rental row to write
        // these onto; the goods form never sends these keys at all.
        if ($rentalData !== [] && $product->isRental()) {
            $product->resolveBaseRental()?->update($rentalData);
        }

        return $product->fresh()->load(self::EAGER);
    }

    public function delete(EngageProduct $product): bool
    {
        return $product->delete();
    }

    /**
     * Auto-generates a SKU when one isn't explicitly provided on create —
     * an uppercase-alnum-and-dash-only string, since this feeds directly
     * into a Code 39 barcode (rendered client-side), which doesn't support
     * lowercase letters or most punctuation. Retries on the rare per-tenant
     * collision rather than trusting randomness alone. Public so
     * GhlProductSyncService can assign one to a GHL-pulled stub product too.
     */
    public function generateUniqueSku(string $tenantId, string $name): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
        $base = substr($base, 0, 6) ?: 'SKU';

        do {
            $candidate = $base.'-'.strtoupper(Str::random(4));
            $exists = EngageProduct::where('engage_organization_location_id', $tenantId)->where('sku', $candidate)->exists();
        } while ($exists);

        return $candidate;
    }

    /**
     * Backfills a SKU (and therefore a printable barcode, generated
     * client-side from the SKU) onto every non-rental goods product in the
     * tenant that doesn't have one yet — e.g. products created via a GHL
     * pull before this feature existed, or via any path that bypassed
     * create()'s auto-generation. Rentals/services are never touched: they
     * have no SKU/barcode concept (see Product/ProductRental docs).
     */
    public function generateMissingSkus(string $tenantId): array
    {
        $products = EngageProduct::byLocation($tenantId)
            ->whereNull('product_rental_id')
            ->where(fn (Builder $q) => $q->whereNull('sku')->orWhere('sku', ''))
            ->get();

        foreach ($products as $product) {
            $product->update(['sku' => $this->generateUniqueSku($tenantId, $product->name)]);
        }

        return ['updated' => $products->count()];
    }

    /** Exact-match SKU lookup for the Product Sales page's barcode scanner. */
    public function findBySku(string $tenantId, string $sku): ?EngageProduct
    {
        return EngageProduct::byLocation($tenantId)
            ->whereNull('product_rental_id')
            ->where('sku', $sku)
            ->with(self::EAGER)
            ->first();
    }

    public function uploadImage(EngageProduct $product, UploadedFile $image): EngageProduct
    {
        $path = $image->store('products', 'public');
        $product->update(['image' => Storage::url($path), 'ghl_image_url' => null]);

        return $product->fresh();
    }

    /**
     * Public "Shop" page: non-rental POS goods only. Unlike listServices()
     * (rentals, whose price/stock live in GHL and can't be filtered in SQL),
     * a regular product's price/quantity/track_product_inventory are local
     * columns, so price range and availability can both be filtered and
     * sorted directly at the DB level — no in-memory Collection pass needed.
     *
     * `available_only` mirrors the frontend's deriveProductStock() fallback
     * definition of "available": inventory isn't tracked at all, or it is
     * and there's at least one unit left. This uses the already-fetched
     * local `quantity` column, not a live per-product GHL stock call — the
     * same "list views use cheap local fallback data, only a single-item
     * view goes live" precedent this codebase already follows elsewhere
     * (e.g. ProductGrid's card-level GHL stock check happens per card, on
     * demand, not as part of the list query itself).
     */
    public function listStorefront(array $filters = []): LengthAwarePaginator
    {
        $query = EngageProduct::query()
            ->whereNull('product_rental_id')
            ->where('status', 'active')
            ->where('available_in_store', true)
            ->with(['categories', 'organizationLocation']);

        $this->scopeToLocationOrAllActiveOrgs($query, $filters);

        if (! empty($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        // Multiple categories, OR'd together — a product matching any one
        // of the selected categories is included.
        if (! empty($filters['category_ids']) && is_array($filters['category_ids'])) {
            $query->whereHas('categories', fn (Builder $q) => $q->whereIn('engage_categories.id', $filters['category_ids']));
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $query->where('price', '>=', (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $query->where('price', '<=', (float) $filters['max_price']);
        }

        if ($filters['available_only'] ?? false) {
            $query->where(function (Builder $q) {
                $q->where('track_product_inventory', false)
                    ->orWhere('quantity', '>', 0);
            });
        }

        match ($filters['sort'] ?? null) {
            'price_asc' => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            'name' => $query->orderBy('name', 'asc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Bookable services for the storefront: GHL-linked rental listings only.
     * Local-only fast query — live detail fetched on show/quote.
     */
    public function listServices(array $filters = []): LengthAwarePaginator
    {
        $query = EngageProduct::query()
            ->whereNotNull('product_rental_id')
            ->where('status', 'active')
            ->with(['rentals.serviceCategory', 'defaultRental.serviceCategory', 'categories', 'amenities', 'features']);

        $this->scopeToLocationOrAllActiveOrgs($query, $filters);

        if (! empty($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (! empty($filters['category_id'])) {
            $query->whereHas('categories', fn (Builder $q) => $q->where('engage_categories.id', $filters['category_id']));
        }

        // service_category_id is deliberately a distinct param from
        // category_id above — that one filters the many-to-many Product
        // Category taxonomy (product_categories pivot); this filters the
        // Services-module ServiceCategory a rental belongs to. The filter
        // value is the local ServiceCategory's own id (matching how
        // category_id above is Category's id, not engage_collection_id),
        // but product_rentals.service_category_id stores the *raw GHL* id —
        // resolve local id -> ghl id first, same indirection
        // EngageProductRental::serviceCategory() itself does for a single row.
        if (! empty($filters['service_category_id'])) {
            // No org filter needed here — service_category_id is already a
            // specific, globally-unique local ULID (and, since a public
            // cross-org call has no single org of its own, there's nothing
            // to scope it to anyway).
            $ghlCategoryId = EngageProductRentalCategory::where('id', $filters['service_category_id'])
                ->value('ghl_category_id');

            // An unmatched/local-only category (no ghl_category_id yet) has
            // nothing to match against on product_rentals — force an empty
            // result rather than silently ignoring the filter.
            $query->whereHas('rentals', fn (Builder $q) => $q->where('service_category_id', $ghlCategoryId ?? '__none__'));
        }

        $services = $query->get();

        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $services = $services->filter(fn (EngageProduct $p) => ($p->fromPrice() ?? 0) >= (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $services = $services->filter(fn (EngageProduct $p) => ($p->fromPrice() ?? 0) <= (float) $filters['max_price']);
        }

        $services = match ($filters['sort'] ?? null) {
            'price_asc' => $services->sortBy(fn (EngageProduct $p) => $p->fromPrice() ?? INF),
            'price_desc' => $services->sortByDesc(fn (EngageProduct $p) => $p->fromPrice() ?? -INF),
            default => $services->sortBy([['created_at', 'desc']]),
        };

        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = max((int) ($filters['page'] ?? 1), 1);

        return new LengthAwarePaginator(
            $services->forPage($page, $perPage)->values(),
            $services->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    /**
     * Shared by listStorefront()/listServices(): every staff caller
     * (authenticated, always scoped to their own org) passes a real
     * engage_organization_location_id and gets byLocation()'s exact
     * pre-existing behavior, unchanged. The public storefront controllers
     * omit it entirely — a guest browsing the homepage/Shop isn't tied to
     * any one organization, and should see every organization's catalog
     * aggregated together (user-directed, 2026-08-19: "each organization
     * can have different categories... why not all rental services").
     * Restricted to non-blocked organizations only, so a blocked org's
     * inventory never surfaces on the public site even though it's still
     * fully intact in the database.
     */
    private function scopeToLocationOrAllActiveOrgs(Builder $query, array $filters): void
    {
        if (! empty($filters['engage_organization_location_id'])) {
            $query->byLocation($filters['engage_organization_location_id']);

            return;
        }

        $query->whereIn(
            'engage_organization_location_id',
            EngageOrganizationLocation::active()->pluck('id')
        );
    }
}
