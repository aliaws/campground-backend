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

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['sub_type'])) {
            $query->where('sub_type', $filters['sub_type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('location', 'like', "%{$filters['search']}%");
            });
        }

        return $query->with(['category', 'prices', 'variations'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);
        return $product->fresh();
    }

    public function delete(Product $product): bool
    {
        return $product->delete();
    }

    public function uploadImage(Product $product, UploadedFile $image): Product
    {
        $path = $image->store('products', 'public');
        $product->update(['image_url' => Storage::url($path)]);
        return $product->fresh();
    }

    public function addPrice(Product $product, array $data): ProductPrice
    {
        return $product->prices()->create($data);
    }

    public function addVariation(Product $product, array $data): ProductVariation
    {
        return $product->variations()->create($data);
    }

    public function getAvailableCampsites(string $tenantId, ?string $checkIn = null, ?string $checkOut = null): LengthAwarePaginator
    {
        $query = Product::campsites()->byTenant($tenantId)->where('status', 'available');

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

        return $query->with(['category', 'amenities', 'features', 'prices'])
            ->paginate(50);
    }
}
