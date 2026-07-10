<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Integrations\GHL\GhlServiceDetail;
use App\Models\Product;
use App\Models\ProductRental;
use Illuminate\Support\Facades\Log;

/**
 * Pulls GHL Calendar Rentals into the minimal local schema: one Product per
 * base listing + one product_rentals row per variant (the base itself included
 * as the "default" row). Only identifiers and listing-page fields are stored —
 * durations, quantities, pricing rules and booking times are read live via
 * GhlRentalGateway, never persisted.
 *
 * GHL rental mental model (scheduling vs payments):
 * - Scheduling layer: GET calendars/services?industryType=rental
 *   Each listing AND each variant is its own service record (_id → product_rentals.ghl_id).
 * - Payments layer: every service/variant auto-creates a Product (productId);
 *   the BASE listing's is stored as products.ghl_product_id, variants' are
 *   fetched live at booking time.
 * - The service *catalog* API (calendars/services/catalog) is for classic Services v2
 *   bookings and is often empty for rental accounts — do NOT use it for sync or listing.
 */
class GhlServiceSyncService
{
    private const RENTAL_INDUSTRY = 'rental';

    public function __construct(
        private GhlClient $client,
        private GhlRentalGateway $gateway,
    ) {}

    /**
     * The payments-layer productId of every rental service (base listings AND
     * variants — the list endpoint returns both as flat entries, each with its
     * own productId). Used by GhlProductSyncService to keep rental-backing
     * payment products out of the general product catalog pull — GHL assigns
     * those an arbitrary/inconsistent productType (PHYSICAL/DIGITAL/SERVICE)
     * that must not be trusted to decide whether something is a rental.
     */
    public function fetchRentalProductIds(): array
    {
        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            return [];
        }

        try {
            $list = $this->client->get('calendars/services', [
                'locationId' => $locationId,
                'industryType' => self::RENTAL_INDUSTRY,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch rental services for product-id exclusion', ['error' => $e->getMessage()]);

            return [];
        }

        return collect($list['services'] ?? [])
            ->pluck('productId')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{pulled: int, errors: int, error_details: array}
     *
     * Base-listing details and their embedded variants' details are each
     * fetched in one concurrent batch (via GhlClient::poolGet) rather than
     * one HTTP round trip at a time. Every fetched detail also warms the
     * gateway's live-detail cache, so the first quote/show after a pull is
     * a cache hit.
     */
    public function pullServices(string $tenantId): array
    {
        $locationId = $this->client->getLocationId();

        if (! $locationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        $list = $this->client->get('calendars/services', [
            'locationId' => $locationId,
            'industryType' => self::RENTAL_INDUSTRY,
        ]);

        $services = $list['services'] ?? [];

        // Only process GHL base listings (variantId = null).
        $bases = collect($services)->filter(function (array $s) {
            $variantId = $s['variantId'] ?? null;

            return $variantId === null || $variantId === '';
        })->values();

        $pulled = 0;
        $errors = [];

        $baseResults = $this->client->poolGet(
            $bases->mapWithKeys(fn ($s) => [$s['_id'] => $this->serviceDetailRequest($s['_id'], $locationId)])->all()
        );

        $baseDetails = [];
        foreach ($baseResults as $ghlBaseId => $result) {
            if ($result instanceof \Throwable) {
                $errors[] = ['service_id' => $ghlBaseId, 'name' => null, 'error' => $result->getMessage()];
                Log::error('GHL rental base detail fetch failed', ['service' => $ghlBaseId, 'error' => $result->getMessage()]);

                continue;
            }

            $baseDetails[$ghlBaseId] = $result['service'] ?? $result;
        }

        // Gather every embedded variant id across all fetched bases so their
        // details can also be fetched as one concurrent batch.
        $variantRequests = [];
        foreach ($baseDetails as $ghlBaseId => $detail) {
            foreach ($detail['variants'] ?? [] as $embedded) {
                $variantId = $embedded['id'] ?? null;
                if ($variantId && $variantId !== $ghlBaseId) {
                    $variantRequests[$variantId] = $this->serviceDetailRequest($variantId, $locationId);
                }
            }
        }

        $variantResults = $this->client->poolGet($variantRequests);

        foreach ($baseDetails as $ghlBaseId => $rawDetail) {
            try {
                $this->gateway->put($ghlBaseId, $rawDetail);
                $baseDetail = new GhlServiceDetail($rawDetail);

                $product = $this->upsertBaseListing($baseDetail, $tenantId);
                $pulled++;

                $seenGhlIds = [$ghlBaseId];

                foreach ($rawDetail['variants'] ?? [] as $embedded) {
                    $variantId = $embedded['id'] ?? null;
                    if (! $variantId || $variantId === $ghlBaseId) {
                        continue;
                    }

                    $variantResult = $variantResults[$variantId] ?? null;

                    if ($variantResult === null || $variantResult instanceof \Throwable) {
                        $errors[] = [
                            'service_id' => $variantId,
                            'name' => $embedded['name'] ?? null,
                            'error' => $variantResult?->getMessage() ?? 'Variant detail fetch failed',
                        ];

                        continue;
                    }

                    $rawVariant = $variantResult['service'] ?? $variantResult;
                    $this->gateway->put($variantId, $rawVariant);
                    $variantDetail = new GhlServiceDetail($rawVariant);

                    if ($variantDetail->baseServiceId() && $variantDetail->baseServiceId() !== $ghlBaseId) {
                        Log::warning('GHL variant parent mismatch — skipping', [
                            'variant_id' => $variantId,
                            'expected_base' => $ghlBaseId,
                            'actual_variant_id' => $variantDetail->baseServiceId(),
                        ]);

                        continue;
                    }

                    $this->upsertVariant($variantDetail, $product, $ghlBaseId, $tenantId);
                    $seenGhlIds[] = $variantId;
                    $pulled++;
                }

                $this->finalizeListing($product, $seenGhlIds, $baseDetail, $ghlBaseId);
            } catch (\Exception $e) {
                $errors[] = ['service_id' => $ghlBaseId, 'name' => $rawDetail['name'] ?? null, 'error' => $e->getMessage()];
                Log::error('GHL rental service pull failed', ['service' => $ghlBaseId, 'error' => $e->getMessage()]);
            }
        }

        return ['pulled' => $pulled, 'errors' => count($errors), 'error_details' => $errors];
    }

    private function serviceDetailRequest(string $ghlId, string $locationId): array
    {
        return [
            'endpoint' => "calendars/services/{$ghlId}",
            'query' => ['locationId' => $locationId, 'industryType' => self::RENTAL_INDUSTRY],
        ];
    }

    /** Identity lives on product_rentals.ghl_id — resolve the Product through it. */
    private function upsertBaseListing(GhlServiceDetail $detail, string $tenantId): Product
    {
        $ghlId = $detail->id();

        if (! $ghlId) {
            throw new \RuntimeException('GHL service detail missing _id');
        }

        $productAttributes = [
            'name' => $detail->name(),
            'product_type' => 'SERVICE',
            'description' => $detail->description(),
            'slug' => $detail->slug(),
            'status' => $detail->isActive() ? 'active' : 'draft',
            'image' => $detail->coverImage(),
            'ghl_product_id' => $detail->paymentsProductId(),
            'quantity' => $detail->quantity(),
            'price' => $detail->basePrice() ?? $detail->paymentAmount(),
            'engage_sync_status' => 'synced',
            'engage_last_synced_at' => now(),
            'tenant_id' => $tenantId,
        ];

        $existing = ProductRental::where('tenant_id', $tenantId)->where('ghl_id', $ghlId)->first();

        if ($existing) {
            $product = $existing->product;
            $product->update($productAttributes);
        } else {
            $product = Product::create($productAttributes);
        }

        $rental = $this->upsertRentalRow($detail, $product, $ghlId, $tenantId);

        return $product->fresh();
    }

    private function upsertVariant(GhlServiceDetail $detail, Product $baseProduct, string $baseGhlId, string $tenantId): ProductRental
    {
        $ghlId = $detail->id();

        if (! $ghlId) {
            throw new \RuntimeException('GHL variant detail missing _id');
        }

        return $this->upsertRentalRow($detail, $baseProduct, $baseGhlId, $tenantId);
    }

    private function upsertRentalRow(GhlServiceDetail $detail, Product $product, string $baseGhlId, string $tenantId): ProductRental
    {
        $isBase = $detail->id() === $baseGhlId;
        $baseListingPrice = $isBase ? ($detail->basePrice() ?? $detail->paymentAmount()) : null;

        // map_position is local-only data — deliberately never written here.
        return ProductRental::updateOrCreate(
            ['tenant_id' => $tenantId, 'ghl_id' => $detail->id()],
            array_filter([
                'name' => $detail->variantName() ?? ($isBase ? 'Regular' : 'Variant'),
                'is_active' => $detail->isActive(),
                'service_duration' => $detail->serviceDuration() ?? $detail->minDuration(),
                'service_duration_unit' => $detail->serviceDurationUnit() ?? $detail->durationUnit(),
                'slug' => $detail->slug(),
                'ghl_product_id' => $detail->paymentsProductId(),
                'listing_price' => $baseListingPrice,
                'product_id' => $product->id,
                'service_category_id' => $detail->serviceCategoryId(),
                'service_id' => $baseGhlId,
            ], fn ($value) => $value !== null)
        );
    }

    /**
     * After variants are synced: pin listing snapshot to the GHL base service
     * (variantId = null) — default rental pointer, price, and product fields.
     */
    private function finalizeListing(Product $product, array $seenGhlIds, GhlServiceDetail $baseDetail, string $baseGhlId): void
    {
        $baseRental = ProductRental::where('product_id', $product->id)
            ->where('ghl_id', $baseGhlId)
            ->where('service_id', $baseGhlId)
            ->first();

        $basePrice = $baseDetail->basePrice() ?? $baseDetail->paymentAmount();

        $listingUpdate = array_filter([
            'name' => $baseDetail->name(),
            'description' => $baseDetail->description(),
            'image' => $baseDetail->coverImage(),
            'ghl_product_id' => $baseDetail->paymentsProductId(),
            'quantity' => $baseDetail->quantity(),
            'price' => $basePrice,
            'product_rental_id' => $baseRental?->id,
        ], fn ($value) => $value !== null);

        if ($listingUpdate !== []) {
            $product->update($listingUpdate);
        }

        if ($baseRental && $basePrice !== null) {
            $baseRental->update(['listing_price' => $basePrice]);
        }

        $pruned = ProductRental::where('product_id', $product->id)
            ->whereNotIn('ghl_id', $seenGhlIds)
            ->get();

        foreach ($pruned as $rental) {
            $rental->update(['is_active' => false]);
            if ($rental->ghl_id) {
                $this->gateway->forget($rental->ghl_id);
            }
        }
    }
}
