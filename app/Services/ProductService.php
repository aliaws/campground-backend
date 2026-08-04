<?php

namespace App\Services;

use App\Models\Product;
use App\Support\LoadsRentalFamilies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductService
{
    use LoadsRentalFamilies;

    private const EAGER = ['categories', 'productRental', 'defaultRental', 'amenities', 'features'];

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Product::query();

        if (! empty($filters['engage_organization_location_id'])) {
            $query->byLocation($filters['engage_organization_location_id']);
        }

        if (! empty($filters['product_type'])) {
            $query->where('product_type', $filters['product_type']);
        }

        if (array_key_exists('is_rental', $filters)) {
            if ((bool) $filters['is_rental']) {
                // Manage Service / map: base listings only (not every variant product).
                $query->baseRentalListing();
            } else {
                $query->where('is_rental', false);
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

        $page = $query->with(self::EAGER)
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);

        $this->loadRentalFamilies($page->getCollection());

        return $page;
    }

    public function create(array $data): Product
    {
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants']);

        if (empty($data['sku'])) {
            $data['sku'] = $this->generateUniqueSku($data['engage_organization_location_id'], $data['name'] ?? '');
        }

        $product = Product::create($data);

        if (! empty($categoryIds)) {
            $product->categories()->sync($categoryIds);
        }

        return $this->eager($product);
    }

    public function update(Product $product, array $data): Product
    {
        $categoryIds = $data['category_ids'] ?? null;
        $amenityIds = $data['amenity_ids'] ?? null;
        $featureIds = $data['feature_ids'] ?? null;
        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants']);

        $product->update($data);

        if ($categoryIds !== null) {
            $product->categories()->sync($categoryIds);
        }

        if ($amenityIds !== null) {
            $product->amenities()->sync($amenityIds);
        }

        if ($featureIds !== null) {
            $product->features()->sync($featureIds);
        }

        return $this->eager($product->fresh());
    }

    public function delete(Product $product): bool
    {
        return $product->delete();
    }

    public function generateUniqueSku(string $locationId, string $name): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
        $base = substr($base, 0, 6) ?: 'SKU';

        do {
            $candidate = $base.'-'.strtoupper(Str::random(4));
            $exists = Product::where('engage_organization_location_id', $locationId)->where('sku', $candidate)->exists();
        } while ($exists);

        return $candidate;
    }

    public function generateMissingSkus(string $locationId): array
    {
        $products = Product::byLocation($locationId)
            ->where('is_rental', false)
            ->where(fn (Builder $q) => $q->whereNull('sku')->orWhere('sku', ''))
            ->get();

        foreach ($products as $product) {
            $product->update(['sku' => $this->generateUniqueSku($locationId, $product->name)]);
        }

        return ['updated' => $products->count()];
    }

    public function findBySku(string $locationId, string $sku): ?Product
    {
        $product = Product::byLocation($locationId)
            ->where('is_rental', false)
            ->where('sku', $sku)
            ->with(self::EAGER)
            ->first();

        if ($product) {
            $this->loadRentalFamilies([$product]);
        }

        return $product;
    }

    public function uploadImage(Product $product, UploadedFile $image): Product
    {
        $path = $image->store('products', 'public');
        $product->update(['image' => Storage::url($path), 'ghl_image_url' => null]);

        return $product->fresh();
    }

    public function listServices(array $filters = []): LengthAwarePaginator
    {
        $query = Product::byLocation($filters['engage_organization_location_id'])
            ->baseRentalListing()
            ->where('status', 'active')
            ->with(['productRental', 'defaultRental', 'categories', 'amenities', 'features']);

        if (! empty($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (! empty($filters['category_id'])) {
            $query->whereHas('categories', fn (Builder $q) => $q->where('categories.id', $filters['category_id']));
        }

        $services = $query->get();
        $this->loadRentalFamilies($services);

        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $services = $services->filter(fn (Product $p) => ($p->fromPrice() ?? 0) >= (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $services = $services->filter(fn (Product $p) => ($p->fromPrice() ?? 0) <= (float) $filters['max_price']);
        }

        $services = match ($filters['sort'] ?? null) {
            'price_asc' => $services->sortBy(fn (Product $p) => $p->fromPrice() ?? INF),
            'price_desc' => $services->sortByDesc(fn (Product $p) => $p->fromPrice() ?? -INF),
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

    private function eager(Product $product): Product
    {
        $product->load(self::EAGER);
        $this->loadRentalFamilies([$product]);

        return $product;
    }
}
