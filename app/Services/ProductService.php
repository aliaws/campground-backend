<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class ProductService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Product::query();

        if (!empty($filters['tenant_id'])) {
            $query->byTenant($filters['tenant_id']);
        }

        if (!empty($filters['product_type'])) {
            $query->where('product_type', $filters['product_type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['engage_sync_status'])) {
            $query->where('engage_sync_status', $filters['engage_sync_status']);
        }

        if (!empty($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('sku', 'like', "%{$filters['search']}%")
                  ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        return $query->with(['categories', 'prices', 'variations', 'amenities', 'features'])
            ->orderBy('display_priority', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Product
    {
        $categoryIds = $data['category_ids'] ?? [];
        $amenityIds = $data['amenity_ids'] ?? [];
        $featureIds = $data['feature_ids'] ?? [];

        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids']);

        $product = Product::create($data);

        if (!empty($categoryIds)) {
            $product->categories()->sync($categoryIds);
        }
        if (!empty($amenityIds)) {
            $product->amenities()->sync($amenityIds);
        }
        if (!empty($featureIds)) {
            $product->features()->sync($featureIds);
        }

        return $product->load(['categories', 'prices', 'variations', 'amenities', 'features']);
    }

    public function update(Product $product, array $data): Product
    {
        $categoryIds = $data['category_ids'] ?? null;
        $amenityIds = $data['amenity_ids'] ?? null;
        $featureIds = $data['feature_ids'] ?? null;

        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids']);

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

        return $product->fresh()->load(['categories', 'prices', 'variations', 'amenities', 'features']);
    }

    public function delete(Product $product): bool
    {
        return $product->delete();
    }

    public function uploadImage(Product $product, UploadedFile $image): Product
    {
        $path = $image->store('products', 'public');
        $product->update(['image' => Storage::url($path)]);
        return $product->fresh();
    }

    public function addPrice(Product $product, array $data): ProductPrice
    {
        return $product->prices()->create($data);
    }

    public function updatePrice(ProductPrice $price, array $data): ProductPrice
    {
        $price->update($data);
        return $price->fresh();
    }

    public function deletePrice(ProductPrice $price): bool
    {
        return $price->delete();
    }

    public function addVariation(Product $product, array $data): ProductVariation
    {
        return $product->variations()->create($data);
    }

    public function updateVariation(ProductVariation $variation, array $data): ProductVariation
    {
        $variation->update($data);
        return $variation->fresh();
    }

    public function deleteVariation(ProductVariation $variation): bool
    {
        return $variation->delete();
    }

    public function getAvailableCampsites(string $tenantId, ?string $checkIn = null, ?string $checkOut = null): LengthAwarePaginator
    {
        $query = Product::service()->byTenant($tenantId)->where('campsite_status', 'available');

        if ($checkIn && $checkOut) {
            $bookedIds = \App\Models\Reservation::where('status', '!=', 'cancelled')
                ->where(function ($q) use ($checkIn, $checkOut) {
                    $q->whereBetween('check_in_date', [$checkIn, $checkOut])
                      ->orWhereBetween('check_out_date', [$checkIn, $checkOut])
                      ->orWhere(function ($qq) use ($checkIn, $checkOut) {
                          $qq->where('check_in_date', '<=', $checkIn)
                             ->where('check_out_date', '>=', $checkOut);
                      });
                })
                ->pluck('product_id');

            $query->whereNotIn('id', $bookedIds);
        }

        return $query->with(['categories', 'amenities', 'features', 'prices'])
            ->paginate(50);
    }
}
