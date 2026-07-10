<?php

namespace App\Http\Resources;

use App\Integrations\GHL\GhlServiceDetail;
use App\Models\Product;
use App\Models\ProductRental;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Live GHL-enriched service payload for show/detail pages. Same key set as
 * ServiceResource — raw GHL is never exposed, only transformed fields.
 */
class LiveServiceResource extends JsonResource
{
    /**
     * @param  array<string, GhlServiceDetail>  $details  keyed by ghl_id
     * @param  array<string, ?array<string, mixed>>  $paymentsByGhlId
     */
    public function __construct(
        Product $product,
        private array $details,
        private array $paymentsByGhlId = [],
    ) {
        parent::__construct($product);
    }

    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $defaultRental = $product->resolveBaseRental();
        $baseDetail = $defaultRental ? ($this->details[$defaultRental->ghl_id] ?? null) : null;
        $basePayments = $defaultRental ? ($this->paymentsByGhlId[$defaultRental->ghl_id] ?? null) : null;
        $baseVariant = $defaultRental && $baseDetail
            ? ServiceVariantResource::fromDetail($product, $defaultRental, $baseDetail, $defaultRental, $basePayments)
            : null;

        $rentals = $product->relationLoaded('rentals')
            ? $product->rentals->where('is_active', true)->values()
            : $product->rentals()->where('is_active', true)->get();

        $sortedRentals = $rentals->sortBy(
            fn (ProductRental $rental) => $rental->isBaseListing() ? 0 : 1
        )->values();

        $variants = $this->buildLiveVariants($product, $sortedRentals, $defaultRental);

        return [
            'id' => $product->id,
            '_id' => $defaultRental?->ghl_id,
            'isActive' => $baseDetail?->isActive() ?? ($product->status === 'active'),
            'isPrivate' => false,
            'name' => $baseVariant['name'] ?? $product->name,
            'slug' => $baseDetail?->slug() ?? $product->slug,
            'description' => $baseVariant['description'] ?? $baseDetail?->description() ?? $product->description,
            'coverImage' => $baseVariant['coverImage'] ?? $baseDetail?->coverImage() ?? $product->image,
            'bookingUnit' => $baseDetail?->bookingUnit() ?? 'day',
            'quantity' => $baseDetail?->quantity() ?? $product->quantity ?? 1,
            'maxQuantity' => $baseDetail?->maxQuantity() ?? $product->quantity ?? 1,
            'images' => $baseVariant['images'] ?? $baseDetail?->images() ?? ($product->image ? [['url' => $product->image, 'name' => $product->name, 'position' => 0, '_id' => null]] : []),
            'serviceCategoryId' => $defaultRental?->service_category_id ?? $baseDetail?->serviceCategoryId(),
            'categoryName' => $product->categories?->first()?->name,
            'variantName' => $baseDetail?->variantName() ?? $defaultRental?->name ?? 'Regular',
            'isVariantsEnabled' => count($variants) > 1,
            'isVariant' => false,
            'variantId' => null,
            'variants' => $variants,
            'pricingRule' => $baseVariant['pricingRule'] ?? $this->formatPricingRule($baseDetail),
            'productId' => $baseVariant['productId'] ?? $baseDetail?->paymentsProductId() ?? $product->ghl_product_id,
            'bookingPeriodType' => $this->deriveBookingPeriodType($baseDetail),
            'minDuration' => $baseDetail?->minDuration(),
            'maxDuration' => $baseDetail?->maxDuration(),
            'durationUnit' => $baseDetail?->durationUnit() ?? 'day',
            'minDurationUnit' => $baseDetail?->durationUnit() ?? 'day',
            'maxDurationUnit' => $baseDetail?->durationUnit() ?? 'day',
            'bookingStartTime' => $baseDetail?->bookingStartTime(),
            'bookingEndTime' => $baseDetail?->bookingEndTime(),
            'hasQuantityEnabled' => ($baseDetail?->maxQuantity() ?? 1) > 1,
            'serviceDuration' => $baseDetail?->serviceDuration() ?? $defaultRental?->service_duration ?? 0,
            'serviceDurationUnit' => $baseDetail?->serviceDurationUnit() ?? $defaultRental?->service_duration_unit ?? 'day',
            'teamMembers' => [],
            'isServiceAvailable' => $baseDetail?->isActive() ?? ($product->status === 'active'),
            'displayPriority' => 0,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'amenities' => [],
            'features' => [],
            'fromPrice' => $baseVariant['payment']['amount'] ?? $baseDetail?->basePrice() ?? $product->fromPrice(),
            'createdAt' => $product->created_at,
            'updatedAt' => $product->updated_at,
        ];
    }

    /** @param Collection<int, ProductRental> $rentals */
    private function buildLiveVariants(Product $product, $rentals, ?ProductRental $defaultRental): array
    {
        return $rentals->map(function (ProductRental $rental) use ($product, $defaultRental) {
            $detail = $this->details[$rental->ghl_id] ?? null;
            $payments = $this->paymentsByGhlId[$rental->ghl_id] ?? null;

            return ServiceVariantResource::fromDetail(
                $product,
                $rental,
                $detail ?? new GhlServiceDetail([]),
                $defaultRental,
                $payments
            );
        })->values()->all();
    }

    private function formatPricingRule(?GhlServiceDetail $detail): ?array
    {
        return ServiceVariantResource::formatPricingRule($detail);
    }

    private function deriveBookingPeriodType(?GhlServiceDetail $detail): string
    {
        if (! $detail) {
            return 'date-selection';
        }

        $type = $detail->bookingPeriodType();
        if ($type) {
            return $type;
        }

        if ($detail->bookingStartTime() && $detail->bookingEndTime()) {
            return 'date-time-selection';
        }

        return 'date-selection';
    }
}
