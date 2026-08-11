<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Integrations\GHL\GhlServiceDetail;
use App\Models\Product;
use App\Models\ProductRental;
use App\Models\ServiceCategory;
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
     * @return array{pulled: int, base_listings_pulled: int, variants_pulled: int, errors: int, error_details: array}
     *
     * Base-listing details and their embedded variants' details are each
     * fetched in one concurrent batch (via GhlClient::poolGet) rather than
     * one HTTP round trip at a time. Every fetched detail also warms the
     * gateway's live-detail cache, so the first quote/show after a pull is
     * a cache hit.
     *
     * `base_listings_pulled`/`variants_pulled` (2026-08-07) are additive to
     * the pre-existing `pulled` total (which counts both together, since a
     * rental listing and its variants are the same GHL entity type in this
     * codebase's data model) — added so callers like the "Pull Data"
     * full-sync feature can report "Services" and "Rentals" as the two
     * distinct counts the feature's requirements ask for. Purely additive:
     * `pulled` keeps its exact original meaning/value.
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
        $baseListingsPulled = 0;
        $variantsPulled = 0;
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
                $baseListingsPulled++;

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
                    $variantsPulled++;
                }

                $this->finalizeListing($product, $seenGhlIds, $baseDetail, $ghlBaseId);
            } catch (\Exception $e) {
                $errors[] = ['service_id' => $ghlBaseId, 'name' => $rawDetail['name'] ?? null, 'error' => $e->getMessage()];
                Log::error('GHL rental service pull failed', ['service' => $ghlBaseId, 'error' => $e->getMessage()]);
            }
        }

        return [
            'pulled' => $pulled,
            'base_listings_pulled' => $baseListingsPulled,
            'variants_pulled' => $variantsPulled,
            'errors' => count($errors),
            'error_details' => $errors,
        ];
    }

    /**
     * `Version: v3` — confirmed via a real captured Postman request/response
     * against this tenant's live account (2026-08-11), not guessed.
     */
    private const SERVICE_CATEGORIES_API_VERSION = 'v3';

    /**
     * Pulls `GET calendars/service-categories` — the category taxonomy each
     * rental's `serviceCategoryId` (captured per-row in upsertRentalRow()
     * below) refers to. Mirrors GhlProductSyncService::pullCategoriesFromGhl()
     * exactly: `updateOrCreate` keyed on the GHL id makes repeated pulls
     * idempotent (no duplicates), and a category that already exists locally
     * is updated in place rather than re-created.
     *
     * Response shape confirmed live (2026-08-11, real captured
     * request/response against this tenant's account, supplied by the
     * user): `{ "serviceCategories": [{ "_id", "name", "isActive",
     * "deleted", "slug", "locationId", "isSystemGenerated",
     * "associationId", "associationType", ... }] }` — this superseded an
     * earlier attempt that couldn't verify the shape live because the
     * connection's OAuth token was missing scope for this endpoint (401);
     * `calendars/groups.readonly` was added to GhlAuthService's requested
     * scopes and the connection re-authorized, which resolved that.
     * `parseServiceCategories()` still checks a couple of fallback keys
     * defensively (cheap, harmless) but `serviceCategories` — already the
     * first key tried — is the real, confirmed shape.
     *
     * **No pagination** — a real live call with `limit`/`offset` query
     * params attached (mirroring GhlProductSyncService::fetchAllGhlCollections()'s
     * pattern for the *different*, actually-paginated `products/collections`
     * endpoint) returned `422 "property limit should not exist / property
     * offset should not exist"`. This endpoint always returns the
     * location's full category list in one response — confirmed by the
     * same real captured request, which sends neither param.
     */
    public function pullServiceCategories(string $tenantId): array
    {
        $results = ['pulled' => 0, 'created' => 0, 'errors' => 0, 'error_details' => [], 'note' => null];

        $locationId = $this->client->getLocationId();

        if (! $locationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        try {
            $response = $this->client->get('calendars/service-categories', [
                'locationId' => $locationId,
                'industryType' => self::RENTAL_INDUSTRY,
            ], self::SERVICE_CATEGORIES_API_VERSION);

            $parsed = $this->parseServiceCategories($response);
            $skippedDeleted = 0;

            foreach ($parsed['items'] as $ghlCategory) {
                $ghlId = $ghlCategory['_id'] ?? $ghlCategory['id'] ?? null;

                // A category GHL has soft-deleted no longer really exists —
                // skip it entirely rather than importing/updating a local
                // row for it (matches finalizeListing()'s own treatment of
                // rentals GHL no longer returns).
                if (! $ghlId || ($ghlCategory['deleted'] ?? false) === true) {
                    $skippedDeleted++;

                    continue;
                }

                $data = [
                    'name' => $ghlCategory['name'] ?? 'Untitled',
                    'is_active' => $ghlCategory['isActive'] ?? true,
                    'engage_last_synced_at' => now(),
                    'tenant_id' => $tenantId,
                ];

                $category = ServiceCategory::where('tenant_id', $tenantId)
                    ->where('ghl_category_id', $ghlId)
                    ->first();

                if ($category) {
                    $category->update($data);
                } else {
                    ServiceCategory::create($data + ['ghl_category_id' => $ghlId]);
                    $results['created']++;
                }

                $results['pulled']++;
            }

            // Distinguishes three outcomes that all otherwise look
            // identical from the outside as a bare "pulled: 0, errors: 0"
            // — the exact ambiguity a different environment's GHL account
            // (never exercised locally) could hit: (a) a genuinely
            // unrecognized response shape — a real problem, so this alone
            // goes into `error_details` (which GhlFullSyncService::runPhase()
            // treats as a phase error and reflects in the overall sync
            // status); (b) every category GHL returned is soft-deleted; (c)
            // GHL's list for this location is genuinely, legitimately empty
            // (nothing configured there yet). (b) and (c) are normal,
            // successful outcomes — not errors, and deliberately don't touch
            // `error_details`/the overall sync status for that reason —
            // but still need to be visible *somewhere*, so they're returned
            // via `note` instead: ServiceCategoryController::pullFromGhl()
            // folds it into the individual Pull button's own message, and
            // it's always logged either way for a server-side check.
            if ($results['pulled'] === 0 && $results['errors'] === 0) {
                if (! $parsed['recognized']) {
                    $note = 'GHL returned a response for calendars/service-categories in an unrecognized shape (top-level keys: '
                        .implode(', ', array_keys($response)).') — 0 categories could be parsed from it.';
                    $results['error_details'][] = ['error' => $note];
                    $results['note'] = $note;
                    Log::warning('GHL service-category response shape not recognized', [
                        'tenant_id' => $tenantId,
                        'top_level_keys' => array_keys($response),
                    ]);
                } elseif ($skippedDeleted > 0) {
                    $results['note'] = "GHL returned {$skippedDeleted} service categor".($skippedDeleted === 1 ? 'y' : 'ies')
                        .' for this location, but all of them are marked deleted — 0 usable categories were pulled.';
                    Log::info('GHL service-category pull: every returned category was deleted', [
                        'tenant_id' => $tenantId,
                        'deleted_count' => $skippedDeleted,
                    ]);
                } else {
                    $results['note'] = 'GHL returned an empty service-category list for this location — no categories are configured there yet.';
                    Log::info('GHL service-category pull: location has zero categories configured', ['tenant_id' => $tenantId]);
                }
            }
        } catch (\Exception $e) {
            $results['errors']++;
            $results['error_details'][] = ['error' => 'GHL service-category list fetch failed: '.$e->getMessage()];
            Log::error('GHL service-category list fetch failed', ['error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * See pullServiceCategories()'s doc comment for why this checks
     * multiple keys. `recognized` tells the caller whether a known shape
     * was actually matched (even if the list inside it was empty) versus
     * nothing matching at all — the two look identical as a bare array
     * otherwise, and that distinction is what makes a silent zero-result
     * pull on an unfamiliar GHL account diagnosable.
     *
     * @return array{items: array, recognized: bool}
     */
    private function parseServiceCategories(array $response): array
    {
        foreach (['serviceCategories', 'categories', 'data'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return ['items' => $response[$key], 'recognized' => true];
            }
        }

        // A bare top-level list (no wrapper key) — array_is_list() confirms
        // it's actually a plain array of items, not an associative payload
        // under some other key this loop didn't anticipate.
        if (array_is_list($response)) {
            return ['items' => $response, 'recognized' => true];
        }

        return ['items' => [], 'recognized' => false];
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
        // Priced for every variant, not just the base listing (2026-07-27) —
        // `listing_price` used to only ever be written for the base row, so
        // the Manage Service Variants tab had no stored price to show for any
        // other variant. Each GhlServiceDetail already carries its own price
        // (same basePrice()/paymentAmount() fields ServiceVariantResource
        // already uses for live per-variant pricing), so this was just an
        // unnecessary gate — no new column needed, the existing one just
        // wasn't being populated for non-base rows.
        $variantPrice = $detail->basePrice() ?? $detail->paymentAmount();

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
                'listing_price' => $variantPrice,
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
