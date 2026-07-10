<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Integrations\GHL\GhlServiceDetail;
use App\Models\Product;
use App\Models\ProductRental;
use Illuminate\Support\Facades\Cache;

/**
 * Live GHL rental-service details with a short server-side cache. This is the
 * single path for reading rental detail data (durations, quantities, pricing
 * rules, booking times) — none of it is stored locally anymore.
 *
 * Cache: ghl:service-detail:{tenant}:{ghl_id}, 5 min TTL. pullServices() warms
 * it with the details it just fetched and forgets pruned ids, so a pull is
 * always followed by fresh reads. Failures are never cached.
 */
class GhlRentalGateway
{
    private const TTL_SECONDS = 300;

    private const RENTAL_INDUSTRY = 'rental';

    public function __construct(
        private GhlClient $client,
    ) {}

    public function fetchServiceDetail(string $ghlServiceId): GhlServiceDetail
    {
        $raw = Cache::remember(
            $this->cacheKey($ghlServiceId),
            self::TTL_SECONDS,
            function () use ($ghlServiceId) {
                $locationId = $this->requireLocationId();
                $response = $this->client->get("calendars/services/{$ghlServiceId}", [
                    'locationId' => $locationId,
                    'industryType' => self::RENTAL_INDUSTRY,
                ]);

                return $response['service'] ?? $response;
            }
        );

        return new GhlServiceDetail($raw);
    }

    /**
     * Details for every rental variant of a listing, keyed by ghl_id. Cache
     * misses are fetched in one concurrent batch. A variant whose fetch fails
     * is omitted (callers render what they have); an empty result for a
     * product with rentals means GHL is unreachable — callers treat that as
     * a live-fetch failure.
     *
     * @return array<string, GhlServiceDetail>
     */
    public function fetchListingBundle(Product $product): array
    {
        $ghlIds = $product->rentals
            ->pluck('ghl_id')
            ->filter()
            ->unique()
            ->values();

        if ($ghlIds->isEmpty()) {
            return [];
        }

        $details = [];
        $misses = [];

        foreach ($ghlIds as $ghlId) {
            $cached = Cache::get($this->cacheKey($ghlId));
            if ($cached !== null) {
                $details[$ghlId] = new GhlServiceDetail($cached);
            } else {
                $misses[] = $ghlId;
            }
        }

        if ($misses !== []) {
            $locationId = $this->requireLocationId();
            $results = $this->client->poolGet(
                collect($misses)->mapWithKeys(fn ($ghlId) => [$ghlId => [
                    'endpoint' => "calendars/services/{$ghlId}",
                    'query' => ['locationId' => $locationId, 'industryType' => self::RENTAL_INDUSTRY],
                ]])->all()
            );

            foreach ($results as $ghlId => $result) {
                if ($result instanceof \Throwable) {
                    continue;
                }

                $raw = $result['service'] ?? $result;
                Cache::put($this->cacheKey($ghlId), $raw, self::TTL_SECONDS);
                $details[$ghlId] = new GhlServiceDetail($raw);
            }
        }

        return $details;
    }

    /**
     * Payments-layer product payloads for each rental variant, keyed by ghl_id.
     *
     * @param  array<string, GhlServiceDetail>  $serviceDetails
     * @return array<string, ?array<string, mixed>>
     */
    public function fetchPaymentsMap(Product $product, array $serviceDetails): array
    {
        $map = [];

        foreach ($product->rentals as $rental) {
            if (! $rental->ghl_id || ! isset($serviceDetails[$rental->ghl_id])) {
                continue;
            }

            $paymentsProductId = $serviceDetails[$rental->ghl_id]->paymentsProductId()
                ?? $rental->ghl_product_id;

            $map[$rental->ghl_id] = $this->fetchPaymentsProduct($paymentsProductId);
        }

        return $map;
    }

    /** Detail for one rental row; throws when GHL is unreachable. */
    public function fetchRentalDetail(ProductRental $rental): GhlServiceDetail
    {
        if (! $rental->ghl_id) {
            throw new \InvalidArgumentException(
                'This rental is not linked to a GHL service. Pull services from GHL first.'
            );
        }

        return $this->fetchServiceDetail($rental->ghl_id);
    }

    /**
     * Calendar service detail merged with the variant's GHL Payments product
     * (name, description, image) when a payments product id is available.
     *
     * @return array{detail: GhlServiceDetail, payments: ?array}
     */
    public function fetchEnrichedRentalDetail(ProductRental $rental): array
    {
        $detail = $this->fetchRentalDetail($rental);
        $paymentsProductId = $detail->paymentsProductId() ?? $rental->ghl_product_id;

        return [
            'detail' => $detail,
            'payments' => $this->fetchPaymentsProduct($paymentsProductId),
        ];
    }

    /** @return ?array<string, mixed> */
    public function fetchPaymentsProduct(?string $ghlProductId): ?array
    {
        if (! $ghlProductId) {
            return null;
        }

        $raw = Cache::remember(
            $this->paymentsProductCacheKey($ghlProductId),
            self::TTL_SECONDS,
            function () use ($ghlProductId) {
                $locationId = $this->requireLocationId();
                $response = $this->client->get("products/{$ghlProductId}", [
                    'locationId' => $locationId,
                ]);

                return $response['product'] ?? $response;
            }
        );

        return is_array($raw) ? $raw : null;
    }

    /** Warm the cache with a detail payload the sync just fetched. */
    public function put(string $ghlServiceId, array $raw): void
    {
        Cache::put($this->cacheKey($ghlServiceId), $raw, self::TTL_SECONDS);
    }

    public function forget(string $ghlServiceId): void
    {
        Cache::forget($this->cacheKey($ghlServiceId));
    }

    private function cacheKey(string $ghlServiceId): string
    {
        $tenantId = $this->client->getSetting()?->tenant_id ?? 'default';

        return "ghl:service-detail:{$tenantId}:{$ghlServiceId}";
    }

    private function paymentsProductCacheKey(string $ghlProductId): string
    {
        $tenantId = $this->client->getSetting()?->tenant_id ?? 'default';

        return "ghl:payments-product:{$tenantId}:{$ghlProductId}";
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
