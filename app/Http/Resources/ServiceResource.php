<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variants = $this->buildVariants();

        return [
            'id' => $this->id,
            '_id' => $this->ghl_service_id,
            'isActive' => $this->status === 'active',
            'isPrivate' => false,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'coverImage' => $this->image,
            'bookingUnit' => $this->booking_unit ?? 'day',
            'quantity' => $this->available_quantity ?? 1,
            'maxQuantity' => $this->max_quantity ?? 1,
            'images' => $this->mapImages($this->medias),
            'serviceCategoryId' => $this->ghl_service_category_id,
            'categoryName' => $this->categories?->first()?->name,
            'variantName' => $this->variant_name ?? 'Regular',
            'isVariantsEnabled' => count($variants) > 1,
            'isVariant' => $this->parent_product_id !== null,
            'variantId' => $this->when($this->parent_product_id !== null, fn () => $this->parent?->ghl_service_id),
            'variants' => $variants,
            'pricingRule' => $this->pricing_rule ?? $this->buildFallbackPricingRule(),
            'productId' => $this->engage_product_id,
            'bookingPeriodType' => $this->deriveBookingPeriodType(),
            'minDuration' => $this->min_duration,
            'maxDuration' => $this->max_duration,
            'durationUnit' => $this->duration_unit ?? 'day',
            'minDurationUnit' => $this->duration_unit ?? 'day',
            'maxDurationUnit' => $this->duration_unit ?? 'day',
            'bookingStartTime' => $this->booking_start_time,
            'bookingEndTime' => $this->booking_end_time,
            'hasQuantityEnabled' => $this->max_quantity !== null && $this->max_quantity > 1,
            'serviceDuration' => $this->min_duration ?? 0,
            'serviceDurationUnit' => $this->duration_unit ?? 'day',
            'teamMembers' => [],
            'isServiceAvailable' => $this->status === 'active',
            'displayPriority' => $this->display_priority,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'amenities' => AmenityResource::collection($this->whenLoaded('amenities')),
            'features' => FeatureResource::collection($this->whenLoaded('features')),
            'fromPrice' => $this->fromPrice(),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }

    private function buildVariants(): array
    {
        $self = $this->buildVariantItem($this->resource, true);
        $children = $this->relationLoaded('serviceVariants')
            ? $this->serviceVariants->map(fn (Product $v) => $this->buildVariantItem($v, false))->values()->all()
            : [];

        return array_merge([$self], $children);
    }

    private function buildVariantItem(Product $product, bool $isBase): array
    {
        return [
            'id' => $product->id,
            '_id' => $product->ghl_service_id,
            'name' => $product->name,
            'variantName' => $product->variant_name ?? 'Regular',
            'description' => $product->description,
            'coverImage' => $product->image,
            'payment' => [
                'amount' => $product->pricing_rule['base_price'] ?? $product->fromPrice() ?? 0,
                'description' => $product->description,
            ],
            'isVariant' => ! $isBase,
            'variantId' => $isBase ? null : $product->parent?->ghl_service_id,
            'productId' => $product->engage_product_id,
            'position' => $isBase ? 0 : ($product->display_priority ?? 1),
            'bookingUnit' => $product->booking_unit ?? 'day',
            'quantity' => $product->available_quantity ?? 1,
            'maxQuantity' => $product->max_quantity ?? 1,
            'minDuration' => $product->min_duration,
            'maxDuration' => $product->max_duration,
            'pricingRule' => $product->pricing_rule,
            'isActive' => $product->status === 'active',
        ];
    }

    private function mapImages(?array $medias): array
    {
        if (empty($medias)) {
            return [];
        }

        return collect($medias)->map(fn ($m, $i) => [
            '_id' => $m['id'] ?? null,
            'url' => $m['url'] ?? null,
            'name' => $m['title'] ?? 'Image '.($i + 1),
            'position' => $m['isFeatured'] ? 0 : $i + 1,
        ])->values()->all();
    }

    private function buildFallbackPricingRule(): ?array
    {
        $price = $this->fromPrice();
        if ($price === null) {
            return null;
        }

        return [
            'basePrice' => ['value' => $price, 'strategy' => 'per_day'],
            'rules' => [],
        ];
    }

    private function deriveBookingPeriodType(): string
    {
        if ($this->booking_start_time && $this->booking_end_time) {
            return 'date-time-selection';
        }

        if ($this->min_duration !== null && $this->max_duration !== null && $this->min_duration === $this->max_duration && $this->min_duration === 1) {
            return 'fixed';
        }

        return 'date-selection';
    }
}
