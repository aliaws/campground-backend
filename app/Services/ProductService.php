<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class ProductService
{
    private const EAGER = ['categories', 'rentals', 'defaultRental'];

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Product::query();

        if (! empty($filters['tenant_id'])) {
            $query->byTenant($filters['tenant_id']);
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

    public function create(array $data): Product
    {
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants']);

        $product = Product::create($data);

        if (! empty($categoryIds)) {
            $product->categories()->sync($categoryIds);
        }

        return $product->load(self::EAGER);
    }

    public function update(Product $product, array $data): Product
    {
        $categoryIds = $data['category_ids'] ?? null;
        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants']);

        $product->update($data);

        if ($categoryIds !== null) {
            $product->categories()->sync($categoryIds);
        }

        return $product->fresh()->load(self::EAGER);
    }

    public function delete(Product $product): bool
    {
        return $product->delete();
    }

    public function uploadImage(Product $product, UploadedFile $image): Product
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
        $query = Product::byTenant($filters['tenant_id'])
            ->whereNotNull('product_rental_id')
            ->where('status', 'active')
            ->with(['rentals', 'defaultRental', 'categories']);

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
}
