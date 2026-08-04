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
    public function pullServices(string $locationId): array
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

                $product = $this->upsertBaseListing($baseDetail, $locationId);
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

                    $this->upsertVariant($variantDetail, $product, $ghlBaseId, $locationId);
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
    private function upsertBaseListing(GhlServiceDetail $detail, string $locationId): Product
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
            'is_rental' => true,
            'engage_sync_status' => 'synced',
            'engage_last_synced_at' => now(),
            'engage_organization_location_id' => $locationId,
        ];

        $existing = ProductRental::where('ghl_id', $ghlId)
            ->whereHas('product', fn ($q) => $q->where('engage_organization_location_id', $locationId))
            ->first();

        if ($existing) {
            $product = $existing->product;
            $product->update($productAttributes);
        } else {
            $product = Product::create($productAttributes);
        }

        $rental = $this->upsertRentalRow($detail, $product, $ghlId, $locationId);

        return $product->fresh();
    }

    private function upsertVariant(GhlServiceDetail $detail, Product $baseProduct, string $baseGhlId, string $locationId): ProductRental
    {
        $ghlId = $detail->id();

        if (! $ghlId) {
            throw new \RuntimeException('GHL variant detail missing _id');
        }

        return $this->upsertRentalRow($detail, $baseProduct, $baseGhlId, $locationId);
    }

    private function upsertRentalRow(GhlServiceDetail $detail, Product $listingProduct, string $baseGhlId, string $locationId): ProductRental
    {
        $isBase = $detail->id() === $baseGhlId;
        $variantPrice = $detail->basePrice() ?? $detail->paymentAmount();

        $attributes = array_filter([
            'name' => $detail->variantName() ?? ($isBase ? 'Regular' : 'Variant'),
            'is_active' => $detail->isActive(),
            'service_duration' => $detail->serviceDuration() ?? $detail->minDuration(),
            'service_duration_unit' => $detail->serviceDurationUnit() ?? $detail->durationUnit(),
            'slug' => $detail->slug(),
            'ghl_product_id' => $detail->paymentsProductId(),
            'listing_price' => $variantPrice,
            'quantity' => $detail->quantity(),
            'max_quantity' => $detail->maxQuantity(),
            'service_category_id' => $detail->serviceCategoryId(),
            'service_id' => $baseGhlId,
        ], fn ($value) => $value !== null);

        // Base: product_rentals.id = listing products.id (shared PK + FK).
        if ($isBase) {
            $existing = ProductRental::find($listingProduct->id)
                ?? ProductRental::where('ghl_id', $detail->id())
                    ->where('service_id', $baseGhlId)
                    ->first();

            if ($existing) {
                if ($existing->id !== $listingProduct->id) {
                    throw new \RuntimeException(
                        "Base product_rental {$existing->id} is not aligned with product {$listingProduct->id}"
                    );
                }
                $existing->update($attributes);

                return $existing->fresh();
            }

            return ProductRental::create(['id' => $listingProduct->id] + $attributes);
        }

        // Variant: own Product + ProductRental sharing the same id (no product_id).
        $existing = ProductRental::where('ghl_id', $detail->id())
            ->where('service_id', $baseGhlId)
            ->first();

        if ($existing) {
            $this->syncVariantProduct($existing->id, $listingProduct, $attributes);
            $existing->update($attributes);

            return $existing->fresh();
        }

        $variantProductId = (string) \Illuminate\Support\Str::ulid();
        $this->syncVariantProduct($variantProductId, $listingProduct, $attributes);

        return ProductRental::create(['id' => $variantProductId, 'ghl_id' => $detail->id()] + $attributes);
    }

    /** Ensure 1:1 Product row for a variant rental (is_rental=true, shared id). */
    private function syncVariantProduct(string $productId, Product $listing, array $rentalAttributes): void
    {
        $variantName = $rentalAttributes['name'] ?? 'Variant';

        Product::query()->updateOrCreate(
            ['id' => $productId],
            [
                'name' => $listing->name.' — '.$variantName,
                'product_type' => 'SERVICE',
                'description' => $listing->description,
                'status' => $listing->status ?? 'active',
                'available_in_store' => $listing->available_in_store ?? true,
                'image' => $listing->image,
                'slug' => $rentalAttributes['slug'] ?? null,
                'quantity' => $rentalAttributes['quantity'] ?? null,
                'price' => $rentalAttributes['listing_price'] ?? null,
                'is_rental' => true,
                'ghl_product_id' => $rentalAttributes['ghl_product_id'] ?? null,
                'engage_sync_status' => 'synced',
                'engage_last_synced_at' => now(),
                'engage_organization_location_id' => $listing->engage_organization_location_id,
            ]
        );
    }

    private function finalizeListing(Product $product, array $seenGhlIds, GhlServiceDetail $baseDetail, string $baseGhlId): void
    {
        $baseRental = ProductRental::find($product->id)
            ?? ProductRental::where('ghl_id', $baseGhlId)
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
            'is_rental' => true,
        ], fn ($value) => $value !== null);

        if ($listingUpdate !== []) {
            $product->update($listingUpdate);
        }

        if ($baseRental) {
            $baseRental->update(array_filter([
                'listing_price' => $basePrice,
                'quantity' => $baseDetail->quantity(),
                'max_quantity' => $baseDetail->maxQuantity(),
            ], fn ($value) => $value !== null));
        }

        $pruned = ProductRental::where('service_id', $baseGhlId)
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
