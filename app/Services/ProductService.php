<?php

namespace App\Services;

use App\Models\EngageOrganizationLocation;
use App\Models\EngageProduct;
use App\Models\EngageProductRental;
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
        // booking_period_type/booking_settings (Manage Service's Booking
        // Settings tab) follow the identical base-rental-only convention.
        $rentalData = array_intersect_key($data, array_flip([
            'listing_price', 'service_duration_unit', 'security_deposit_amount',
            'booking_period_type', 'booking_settings',
        ]));

        // Drop any fixed-duration interval missing a duration/durationUnit
        // before it ever reaches the database — validation allows them
        // through individually (see UpdateProductRequest's own comment) so
        // one malformed row never rejects the whole save.
        if (isset($rentalData['booking_settings']['serviceDurations'])) {
            $rentalData['booking_settings']['serviceDurations'] = array_values(array_filter(
                $rentalData['booking_settings']['serviceDurations'],
                fn ($d) => isset($d['duration'], $d['durationUnit']) && $d['duration'] !== '' && $d['durationUnit'] !== ''
            ));
        }
        // Manage Service's Category field — see UpdateProductRequest's doc
        // comment: the value is the raw GHL category id already stored
        // as-is on product_rentals.service_category_id, so no local-id
        // translation is needed here, only routing it to the right row.
        $hasServiceCategoryUpdate = array_key_exists('service_category_id', $data);
        $serviceCategoryGhlId = $data['service_category_id'] ?? null;
        // Manage Service's Variants tab — never the base/default rental
        // (that's $rentalData above), only the other variant rows.
        $variants = $data['variants'] ?? null;
        unset(
            $data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants'],
            $data['listing_price'], $data['service_duration_unit'], $data['security_deposit_amount'],
            $data['service_category_id'], $data['booking_period_type'], $data['booking_settings'],
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

        if ($hasServiceCategoryUpdate && $product->isRental()) {
            $product->resolveBaseRental()?->update(['service_category_id' => $serviceCategoryGhlId]);
        }

        // Keep both the product's own is_active and the base rental's
        // is_active in lockstep with the product's status whenever a
        // rental's status is saved — is_active (on both rows) is what
        // actually mirrors Lead Connector's real isActive flag (see
        // GhlServiceDetail::isActive()/GhlServiceSyncService::upsertRentalRow()),
        // so without this they could silently drift apart the moment status
        // was ever edited independently of a GHL pull. `status` itself is
        // left completely untouched here for goods, which have no rental
        // row and keep their own independent active/draft/archived status.
        if (array_key_exists('status', $data) && $product->isRental()) {
            $isActive = $data['status'] === 'active';
            $product->update(['is_active' => $isActive]);
            $product->resolveBaseRental()?->update(['is_active' => $isActive]);
        }

        // Scoped to product_id === $product->id — never trusts an id's mere
        // presence in the validated payload as proof of ownership, since a
        // client could otherwise pass another product's variant id.
        if ($variants !== null && $product->isRental()) {
            foreach ($variants as $variantData) {
                if (empty($variantData['id'])) {
                    continue;
                }

                $variantUpdate = array_intersect_key($variantData, array_flip(['listing_price', 'is_active']));
                if ($variantUpdate === []) {
                    continue;
                }

                EngageProductRental::where('id', $variantData['id'])
                    ->where('product_id', $product->id)
                    ->update($variantUpdate);
            }
        }

        return $product->fresh()->load(self::EAGER);
    }

    public function addImage(EngageProduct $product, UploadedFile $image): EngageProduct
    {
        $path = $image->store('products', 'public');
        $images = $product->images ?? [];
        $nextPosition = empty($images) ? 0 : (max(array_column($images, 'position')) + 1);
        $images[] = ['_id' => null, 'url' => Storage::url($path), 'name' => $product->name, 'position' => $nextPosition];

        $product->update(['images' => $images]);

        return $product->fresh();
    }

    public function removeImage(EngageProduct $product, int $position): EngageProduct
    {
        $images = collect($product->images ?? [])
            ->reject(fn ($img) => ($img['position'] ?? null) === $position)
            ->values()
            ->map(fn ($img, $i) => array_merge($img, ['position' => $i]))
            ->all();

        // Removing whatever was at position:0 changes the cover image
        // (EngageProduct::image() reads position:0) — clear the GHL-CDN
        // cache marker the same way uploadImage() already does whenever
        // the effective cover photo changes, so a later GHL sync re-uploads
        // the new cover instead of reusing a stale cached URL.
        $update = ['images' => $images];
        if ($position === 0) {
            $update['ghl_image_url'] = null;
        }

        $product->update($update);

        return $product->fresh();
    }

    /** Reorders so the given position becomes the new position:0 (cover/default image). */
    public function setCoverImage(EngageProduct $product, int $position): EngageProduct
    {
        $images = collect($product->images ?? []);
        $target = $images->firstWhere('position', $position);

        if (! $target || $position === 0) {
            return $product;
        }

        $rest = $images->reject(fn ($img) => ($img['position'] ?? null) === $position)->values();
        $reordered = collect([$target])->concat($rest)
            ->values()
            ->map(fn ($img, $i) => array_merge($img, ['position' => $i]))
            ->all();

        // See removeImage()'s comment — the cover image is changing here too.
        $product->update(['images' => $reordered, 'ghl_image_url' => null]);

        return $product->fresh();
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

        // Array counterpart to service_category_id above, additive and
        // only ever sent by the public storefront (2026-08-19): once
        // PublicServiceCategoryResource::groupByName() collapses several
        // organizations' identically-named categories into one filter
        // chip, selecting that chip needs to match rentals under ANY of
        // the underlying per-org category ids, not just one.
        if (! empty($filters['service_category_ids']) && is_array($filters['service_category_ids'])) {
            $ghlCategoryIds = EngageProductRentalCategory::whereIn('id', $filters['service_category_ids'])
                ->pluck('ghl_category_id')
                ->filter()
                ->values();

            $query->whereHas('rentals', fn (Builder $q) => $q->whereIn(
                'service_category_id',
                $ghlCategoryIds->isEmpty() ? ['__none__'] : $ghlCategoryIds
            ));
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

        $activeIds = EngageOrganizationLocation::active()->pluck('id');

        // Narrows the public storefront to a caller-picked subset of
        // organizations (2026-08-19, the customer homepage's Organization
        // filter) — always intersected with the already-active set above,
        // never trusted on its own, so a blocked/uninstalled org id passed
        // in via the querystring can never leak its inventory back in.
        // Additive: only PublicServiceController sends this key today, so
        // every other existing caller (Shop included) is unaffected.
        if (! empty($filters['organization_ids']) && is_array($filters['organization_ids'])) {
            $activeIds = $activeIds->intersect($filters['organization_ids'])->values();
        }

        $query->whereIn('engage_organization_location_id', $activeIds);
    }
}
