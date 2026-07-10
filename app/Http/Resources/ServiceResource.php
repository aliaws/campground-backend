<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductRental;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Local-only service payload for index listings and show fallback when GHL
 * is unreachable. Same key set as LiveServiceResource for frontend compat.
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $defaultRental = $product->resolveBaseRental();

        $rentals = $product->relationLoaded('rentals')
            ? $product->rentals->where('is_active', true)->values()
            : $product->rentals()->where('is_active', true)->get();

        $sortedRentals = $rentals->sortBy(
            fn (ProductRental $rental) => $rental->isBaseListing() ? 0 : 1
        )->values();

        $variants = $this->buildLocalVariants($product, $sortedRentals);
        $listingPrice = $product->defaultVariantPrice();

        return [
            'id' => $product->id,
            '_id' => $defaultRental?->ghl_id,
            'isActive' => $product->status === 'active',
            'isPrivate' => false,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'coverImage' => $product->image,
            'bookingUnit' => 'day',
            'quantity' => $product->quantity ?? 1,
            'maxQuantity' => $product->quantity ?? 1,
            'images' => $product->image ? [['url' => $product->image, 'name' => $product->name, 'position' => 0, '_id' => null]] : [],
            'serviceCategoryId' => $defaultRental?->service_category_id,
            'categoryName' => $product->categories?->first()?->name,
            'variantName' => $defaultRental?->name ?? 'Regular',
            'isVariantsEnabled' => count($variants) > 1,
            'isVariant' => false,
            'variantId' => null,
            'variants' => $variants,
            'pricingRule' => $this->buildLocalPricingRule($listingPrice),
            'productId' => $product->ghl_product_id,
            'bookingPeriodType' => 'date-selection',
            'minDuration' => null,
            'maxDuration' => null,
            'durationUnit' => 'day',
            'minDurationUnit' => 'day',
            'maxDurationUnit' => 'day',
            'bookingStartTime' => null,
            'bookingEndTime' => null,
            'hasQuantityEnabled' => ($product->quantity ?? 1) > 1,
            'serviceDuration' => $defaultRental?->service_duration ?? 0,
            'serviceDurationUnit' => $defaultRental?->service_duration_unit ?? 'day',
            'teamMembers' => [],
            'isServiceAvailable' => $product->status === 'active',
            'displayPriority' => 0,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'amenities' => [],
            'features' => [],
            'fromPrice' => $listingPrice,
            'createdAt' => $product->created_at,
            'updatedAt' => $product->updated_at,
        ];
    }

    /** @param Collection<int, ProductRental> $rentals */
    private function buildLocalVariants(Product $product, $rentals): array
    {
        $listingPrice = $product->defaultVariantPrice();

        if ($rentals->isEmpty()) {
            return [[
                'id' => $product->id,
                '_id' => null,
                'name' => $product->name,
                'variantName' => 'Regular',
                'description' => $product->description,
                'coverImage' => $product->image,
                'payment' => ['amount' => (float) ($listingPrice ?? 0), 'description' => $product->description],
                'isVariant' => false,
                'variantId' => null,
                'productId' => $product->ghl_product_id,
                'position' => 0,
                'bookingUnit' => 'day',
                'quantity' => $product->quantity ?? 1,
                'maxQuantity' => $product->quantity ?? 1,
                'minDuration' => null,
                'maxDuration' => null,
                'pricingRule' => $this->buildLocalPricingRule($listingPrice),
                'isActive' => $product->status === 'active',
            ]];
        }

        return $rentals->map(function (ProductRental $rental) use ($product, $listingPrice) {
            $isDefault = $rental->isBaseListing();
            $variantPrice = $isDefault ? (float) ($listingPrice ?? 0) : 0.0;

            return [
                'id' => $isDefault ? $product->id : $rental->id,
                '_id' => $rental->ghl_id,
                'name' => $product->name,
                'variantName' => $rental->name ?? 'Regular',
                'description' => $product->description,
                'coverImage' => $product->image,
                'payment' => [
                    'amount' => $variantPrice,
                    'description' => $product->description,
                ],
                'isVariant' => ! $isDefault,
                'variantId' => $isDefault ? null : $rental->service_id,
                'productId' => $rental->ghl_product_id ?? $product->ghl_product_id,
                'position' => $isDefault ? 0 : 1,
                'bookingUnit' => 'day',
                'quantity' => $product->quantity ?? 1,
                'maxQuantity' => $product->quantity ?? 1,
                'minDuration' => null,
                'maxDuration' => null,
                'pricingRule' => $isDefault ? $this->buildLocalPricingRule($listingPrice) : null,
                'isActive' => $rental->is_active,
            ];
        })->values()->all();
    }

    private function buildLocalPricingRule(?float $listingPrice): ?array
    {
        if ($listingPrice === null) {
            return null;
        }

        return [
            'basePrice' => ['value' => $listingPrice, 'strategy' => 'per_day'],
            'base_price' => $listingPrice,
            'base_price_strategy' => 'per_day',
            'rules' => [],
        ];
    }
}
