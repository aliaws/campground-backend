<?php

namespace App\Services;

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
            $query->whereHas('categories', fn (Builder $q) => $q->where('categories.id', $filters['category_id']));
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
        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants']);

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
     * Bookable services for the storefront: GHL-linked rental listings only.
     * Local-only fast query — live detail fetched on show/quote.
     */
    public function listServices(array $filters = []): LengthAwarePaginator
    {
        $query = EngageProduct::byLocation($filters['engage_organization_location_id'])
            ->whereNotNull('product_rental_id')
            ->where('status', 'active')
            ->with(['rentals.serviceCategory', 'defaultRental.serviceCategory', 'categories', 'amenities', 'features']);

        if (! empty($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (! empty($filters['category_id'])) {
            $query->whereHas('categories', fn (Builder $q) => $q->where('categories.id', $filters['category_id']));
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
            $ghlCategoryId = EngageProductRentalCategory::where('engage_organization_location_id', $filters['engage_organization_location_id'])
                ->where('id', $filters['service_category_id'])
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
}
