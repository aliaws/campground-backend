<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class ProductService
{
    private const EAGER = ['categories', 'prices', 'variants.options', 'amenities', 'features'];

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Product::query();

        if (! empty($filters['tenant_id'])) {
            $query->byTenant($filters['tenant_id']);
        }

        if (! empty($filters['product_type'])) {
            $query->where('product_type', $filters['product_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['engage_sync_status'])) {
            $query->where('engage_sync_status', $filters['engage_sync_status']);
        }

        if (! empty($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('sku', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        return $query->with(self::EAGER)
            ->orderBy('display_priority', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Product
    {
        $categoryIds = $data['category_ids'] ?? [];
        $amenityIds = $data['amenity_ids'] ?? [];
        $featureIds = $data['feature_ids'] ?? [];
        $variants = $data['variants'] ?? null;

        unset($data['category_ids'], $data['amenity_ids'], $data['feature_ids'], $data['variants']);

        $product = Product::create($data);

        if (! empty($categoryIds)) {
            $product->categories()->sync($categoryIds);
        }
        if (! empty($amenityIds)) {
            $product->amenities()->sync($amenityIds);
        }
        if (! empty($featureIds)) {
            $product->features()->sync($featureIds);
        }
        if ($variants !== null) {
            $this->syncVariants($product, $variants);
        }

        return $product->load(self::EAGER);
    }

    public function update(Product $product, array $data): Product
    {
        $categoryIds = $data['category_ids'] ?? null;
        $amenityIds = $data['amenity_ids'] ?? null;
        $featureIds = $data['feature_ids'] ?? null;
        $variants = $data['variants'] ?? null;

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
        if ($variants !== null) {
            $this->syncVariants($product, $variants);
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
        // Clear ghl_image_url so the next GHL sync re-uploads the new file
        $product->update(['image' => Storage::url($path), 'ghl_image_url' => null]);

        return $product->fresh();
    }

    // ── Prices ────────────────────────────────────────────────────────────────

    public function addPrice(Product $product, array $data): ProductPrice
    {
        return $product->prices()->create($data);
    }

    public function updatePrice(ProductPrice $price, array $data): ProductPrice
    {
        $price->update($data);

        return $price->fresh();
    }

    // ── Variants ──────────────────────────────────────────────────────────────

    /**
     * Full upsert-and-prune sync of all variant groups and their options.
     * Incoming payload:
     * [
     *   { id?, name, position?, options: [{ id?, name, position? }] }
     * ]
     */
    public function syncVariants(Product $product, array $variants): void
    {
        $incomingVariantIds = collect($variants)
            ->pluck('id')
            ->filter()
            ->values()
            ->toArray();

        // Remove variant groups that were deleted in the UI
        $product->variants()
            ->whereNotIn('id', $incomingVariantIds)
            ->each(fn (ProductVariant $v) => $v->options()->delete() || $v->delete());

        foreach ($variants as $position => $variantData) {
            $variantId = $variantData['id'] ?? null;

            $variant = $variantId
                ? $product->variants()->find($variantId)
                : null;

            if ($variant) {
                $variant->update([
                    'name' => $variantData['name'],
                    'position' => $variantData['position'] ?? $position,
                ]);
            } else {
                $variant = $product->variants()->create([
                    'name' => $variantData['name'],
                    'position' => $variantData['position'] ?? $position,
                ]);
            }

            $options = $variantData['options'] ?? [];
            $incomingOptionIds = collect($options)->pluck('id')->filter()->values()->toArray();

            // Remove options deleted in the UI
            $variant->options()->whereNotIn('id', $incomingOptionIds)->delete();

            foreach ($options as $optPosition => $optionData) {
                $optionId = $optionData['id'] ?? null;
                $option = $optionId ? $variant->options()->find($optionId) : null;

                $fields = [
                    'name' => $optionData['name'],
                    'position' => $optionData['position'] ?? $optPosition,
                ];

                if ($option) {
                    $option->update($fields);
                } else {
                    $variant->options()->create(array_merge($fields, [
                        'product_id' => $product->id,
                    ]));
                }
            }
        }
    }

    // ── Available campsites ───────────────────────────────────────────────────

    public function getAvailableCampsites(string $tenantId, ?string $checkIn = null, ?string $checkOut = null): LengthAwarePaginator
    {
        $query = Product::service()->byTenant($tenantId)->where('campsite_status', 'available');

        if ($checkIn && $checkOut) {
            $bookedIds = Reservation::where('status', '!=', 'cancelled')
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
