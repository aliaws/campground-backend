<?php

namespace App\Http\Resources;

use App\Integrations\GHL\GhlServiceDetail;
use App\Models\Product;
use App\Models\ProductRental;

/**
 * Builds a single ServiceVariant-shaped array from live GHL detail.
 * Shared by LiveServiceResource and the per-variant public endpoint.
 */
class ServiceVariantResource
{
    /**
     * @param  ?array<string, mixed>  $paymentsProduct  GHL Payments product payload
     */
    public static function fromDetail(
        Product $product,
        ProductRental $rental,
        GhlServiceDetail $detail,
        ?ProductRental $defaultRental = null,
        ?array $paymentsProduct = null,
    ): array {
        $defaultRental ??= $product->resolveBaseRental();
        $isDefault = $rental->isBaseListing();
        $variantPrice = self::resolveVariantPrice($detail, $paymentsProduct, $product, $isDefault);

        return [
            'id' => $isDefault ? $product->id : $rental->id,
            '_id' => $rental->ghl_id,
            'name' => self::resolveName($detail, $paymentsProduct, $product),
            'variantName' => $detail->variantName() ?? $rental->name ?? 'Regular',
            'description' => self::resolveDescription($detail, $paymentsProduct, $product),
            'coverImage' => self::resolveCoverImage($detail, $paymentsProduct, $product),
            'payment' => [
                'amount' => $variantPrice,
                'description' => self::resolveDescription($detail, $paymentsProduct, $product),
            ],
            'isVariant' => ! $isDefault,
            'variantId' => $isDefault ? null : $rental->service_id,
            'productId' => $detail->paymentsProductId() ?? $rental->ghl_product_id ?? $product->ghl_product_id,
            'position' => $isDefault ? 0 : 1,
            'bookingUnit' => $detail->bookingUnit() ?? 'day',
            'quantity' => $detail->quantity() ?? $product->quantity ?? 1,
            'maxQuantity' => $detail->maxQuantity() ?? $product->quantity ?? 1,
            'minDuration' => $detail->minDuration(),
            'maxDuration' => $detail->maxDuration(),
            'pricingRule' => self::formatPricingRule($detail, $variantPrice),
            'isActive' => $detail->isActive() ?? $rental->is_active,
            'bookingStartTime' => $detail->bookingStartTime(),
            'bookingEndTime' => $detail->bookingEndTime(),
            'durationUnit' => $detail->durationUnit() ?? 'day',
            'images' => self::resolveImages($detail, $paymentsProduct, $product),
        ];
    }

    public static function formatPricingRule(?GhlServiceDetail $detail, ?float $fallbackPrice = null): ?array
    {
        if (! $detail) {
            return $fallbackPrice !== null
                ? self::pricingRuleFromAmount($fallbackPrice)
                : null;
        }

        $rule = $detail->pricingRule();
        if ($rule) {
            return [
                'name' => $rule['name'] ?? null,
                'applies_to' => $rule['applies_to'] ?? 'rental',
                'basePrice' => ['value' => $rule['base_price'], 'strategy' => $rule['base_price_strategy'] ?? 'per_day'],
                'base_price' => $rule['base_price'],
                'base_price_strategy' => $rule['base_price_strategy'] ?? 'per_day',
                'rules' => $rule['rules'] ?? [],
                'security_deposit_amount' => $rule['security_deposit_amount'] ?? null,
                'security_deposit_refundable' => $rule['security_deposit_refundable'] ?? true,
            ];
        }

        $amount = $detail->basePrice() ?? $detail->paymentAmount() ?? $fallbackPrice;

        return $amount !== null ? self::pricingRuleFromAmount($amount) : null;
    }

    /** @param  ?array<string, mixed>  $paymentsProduct */
    private static function resolveName(GhlServiceDetail $detail, ?array $paymentsProduct, Product $product): string
    {
        return (string) ($paymentsProduct['name'] ?? $detail->name() ?? $product->name);
    }

    /** @param  ?array<string, mixed>  $paymentsProduct */
    private static function resolveDescription(GhlServiceDetail $detail, ?array $paymentsProduct, Product $product): ?string
    {
        return $paymentsProduct['description'] ?? $detail->description() ?? $product->description;
    }

    /** @param  ?array<string, mixed>  $paymentsProduct */
    private static function resolveCoverImage(GhlServiceDetail $detail, ?array $paymentsProduct, Product $product): ?string
    {
        return $paymentsProduct['image'] ?? $detail->coverImage() ?? $product->image;
    }

    /**
     * @param  ?array<string, mixed>  $paymentsProduct
     * @return array<int, array{_id: ?string, url: ?string, name: string, position: int}>
     */
    private static function resolveImages(GhlServiceDetail $detail, ?array $paymentsProduct, Product $product): array
    {
        $images = $detail->images();
        if ($images !== []) {
            return $images;
        }

        $image = self::resolveCoverImage($detail, $paymentsProduct, $product);
        if (! $image) {
            return [];
        }

        return [[
            '_id' => null,
            'url' => $image,
            'name' => self::resolveName($detail, $paymentsProduct, $product),
            'position' => 0,
        ]];
    }

    /** @param  ?array<string, mixed>  $paymentsProduct */
    private static function resolveVariantPrice(
        GhlServiceDetail $detail,
        ?array $paymentsProduct,
        Product $product,
        bool $isDefault,
    ): float {
        $fromService = $detail->basePrice() ?? $detail->paymentAmount();
        if ($fromService !== null) {
            return (float) $fromService;
        }

        if (isset($paymentsProduct['price'])) {
            return (float) $paymentsProduct['price'];
        }

        if ($isDefault && $product->price !== null) {
            return (float) $product->price;
        }

        return 0.0;
    }

    private static function pricingRuleFromAmount(float $amount): array
    {
        return [
            'basePrice' => ['value' => $amount, 'strategy' => 'per_day'],
            'base_price' => $amount,
            'base_price_strategy' => 'per_day',
            'rules' => [],
        ];
    }
}
