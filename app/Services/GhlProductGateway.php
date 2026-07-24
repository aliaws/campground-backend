<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * Live GHL product price + inventory, with a short server-side cache — the
 * same "fetch live, cache briefly, never persist locally" pattern already
 * used by GhlRentalGateway for rental availability. A GHL Price object
 * (GET products/{id}/price/) already carries its own live stock fields
 * (trackInventory/availableQuantity/allowOutOfStockPurchases) alongside the
 * price itself, so one call covers both price and stock for a product.
 */
class GhlProductGateway
{
    private const TTL_SECONDS = 300;

    public function __construct(
        private GhlClient $client,
    ) {}

    /**
     * Cached (5 min) default-price detail for a product's default (no
     * variantOptionIds) price.
     *
     * @return ?array{price_id: string, amount: float, currency: string, track_inventory: bool, available_quantity: ?int, allow_out_of_stock_purchases: bool}
     */
    public function fetchDefaultPriceDetail(Product $product): ?array
    {
        if (! $product->ghl_product_id) {
            return null;
        }

        return Cache::remember(
            $this->cacheKey($product->ghl_product_id),
            self::TTL_SECONDS,
            fn () => $this->fetchLive($product->ghl_product_id)
        );
    }

    /**
     * UNCACHED live fetch — used immediately before a checkout stock
     * decision/decrement to minimize the read-then-write race window against
     * GHL's own inventory numbers.
     *
     * @return ?array{price_id: string, amount: float, currency: string, track_inventory: bool, available_quantity: ?int, allow_out_of_stock_purchases: bool}
     */
    public function fetchFreshDefaultPriceDetail(Product $product): ?array
    {
        if (! $product->ghl_product_id) {
            return null;
        }

        $detail = $this->fetchLive($product->ghl_product_id);

        if ($detail !== null) {
            Cache::put($this->cacheKey($product->ghl_product_id), $detail, self::TTL_SECONDS);
        }

        return $detail;
    }

    /**
     * POST /products/inventory — bulk-shaped endpoint, called here with a
     * single item. GHL sets an absolute quantity; it has no atomic
     * decrement operation, so the caller is expected to have just read a
     * fresh quantity (via fetchFreshDefaultPriceDetail) and computed the
     * new value itself.
     */
    public function updateInventory(string $priceId, int $newAvailableQuantity, bool $allowOutOfStockPurchases): void
    {
        $locationId = $this->requireLocationId();

        $this->client->post('products/inventory', [
            'altId' => $locationId,
            'altType' => 'location',
            'items' => [[
                'priceId' => $priceId,
                'availableQuantity' => max($newAvailableQuantity, 0),
                'allowOutOfStockPurchases' => $allowOutOfStockPurchases,
            ]],
        ]);
    }

    private function fetchLive(string $ghlProductId): ?array
    {
        $locationId = $this->requireLocationId();

        $response = $this->client->get("products/{$ghlProductId}/price/", [
            'locationId' => $locationId,
        ]);

        $prices = $response['prices'] ?? $response['data'] ?? [];
        $default = collect($prices)->first(fn ($p) => empty($p['variantOptionIds'] ?? []));

        if (! $default) {
            return null;
        }

        $priceId = $default['_id'] ?? $default['id'] ?? null;
        if (! $priceId) {
            return null;
        }

        return [
            'price_id' => $priceId,
            'amount' => (float) ($default['amount'] ?? 0),
            'currency' => $default['currency'] ?? 'USD',
            'track_inventory' => (bool) ($default['trackInventory'] ?? false),
            'available_quantity' => isset($default['availableQuantity']) ? (int) $default['availableQuantity'] : null,
            'allow_out_of_stock_purchases' => (bool) ($default['allowOutOfStockPurchases'] ?? false),
        ];
    }

    private function cacheKey(string $ghlProductId): string
    {
        $tenantId = $this->client->getSetting()?->tenant_id ?? 'default';

        return "ghl:product-price:{$tenantId}:{$ghlProductId}";
    }

    private function requireLocationId(): string
    {
        $locationId = $this->client->getLocationId();

        if (! $locationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        return $locationId;
    }
}
