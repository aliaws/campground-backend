<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Integrations\GHL\GhlServiceDetail;
use App\Models\EngageCategory;
use App\Models\EngageProduct;
use App\Models\EngageProductRental;
use App\Models\EngageProductRentalCategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    /**
     * `Version` header for the outbound service-update PUT — deliberately
     * omitted (falls back to the connection's own configured api_version,
     * same as every other pre-existing `calendars/services` call in this
     * class), since nothing in the real captured request/response this
     * method was built from indicated a version different from what those
     * calls already use.
     */
    public function __construct(
        private GhlClient $client,
        private GhlRentalGateway $gateway,
        private GhlImageSyncService $imageSync,
    ) {}

    /**
     * Pushes a Manage Service edit-form save to Lead Connector via
     * `PUT calendars/services/{id}` — the real captured request/response
     * this was built from is quoted in full in the payload shape below.
     * Called from ProductController::update() BEFORE the local save is
     * ever persisted (mirrors CustomerService::hardDelete()'s
     * GHL-first-then-local ordering) — a failed push here means the local
     * database is never touched at all, so it can never end up out of sync
     * with what Lead Connector actually has.
     *
     * $incoming is the validated request payload (the same array
     * ProductService::update() will apply locally right after this call
     * succeeds) — every field is read as "the incoming value if the
     * request actually included it, else the product/rental's current
     * stored value," so a caller that only sent a subset of fields (rather
     * than this app's own Manage Service form, which always sends the
     * complete state) can never accidentally blank out an untouched field
     * on Lead Connector's side.
     *
     * A no-op (not an error) when the base rental has no `ghl_id` yet — a
     * rental can only ever come from a Lead Connector pull to begin with,
     * so this is purely defensive for test/seed data, not a real production
     * path.
     */
    public function pushServiceUpdateToGhl(EngageProduct $product, array $incoming): void
    {
        $rental = $product->resolveBaseRental();

        if (! $rental || ! $rental->ghl_id) {
            return;
        }

        $product->update(['engage_sync_status' => 'pending']);

        try {
            $payload = $this->buildServiceUpdatePayload($product, $rental, $incoming);
            $this->client->put("calendars/services/{$rental->ghl_id}", $payload);
            $product->update(['engage_sync_status' => 'synced', 'engage_last_synced_at' => now()]);
        } catch (\Exception $e) {
            $product->update(['engage_sync_status' => 'error']);
            Log::error('GHL service update push failed', [
                'product_id' => $product->id,
                'ghl_id' => $rental->ghl_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array<string, mixed> the exact shape confirmed against a real
     *                              captured PUT calendars/services/{id}
     *                              request/response for this tenant
     */
    private function buildServiceUpdatePayload(EngageProduct $product, EngageProductRental $rental, array $incoming): array
    {
        $name = $incoming['name'] ?? $product->name;
        $description = $incoming['description'] ?? $product->description;
        $status = $incoming['status'] ?? $product->status;
        $isActive = $status === 'active';
        $listingPrice = (float) (array_key_exists('listing_price', $incoming) ? $incoming['listing_price'] : $rental->listing_price ?? 0);
        $serviceDurationUnit = $incoming['service_duration_unit'] ?? $rental->service_duration_unit ?? 'day';
        $serviceCategoryId = array_key_exists('service_category_id', $incoming) ? $incoming['service_category_id'] : $rental->service_category_id;
        $bookingPeriodType = $incoming['booking_period_type'] ?? $rental->booking_period_type ?? 'date-time-selection';
        $bookingSettings = array_key_exists('booking_settings', $incoming) ? $incoming['booking_settings'] : ($rental->booking_settings ?? []);
        $quantity = (int) (array_key_exists('quantity', $incoming) ? ($incoming['quantity'] ?? 1) : ($product->quantity ?? 1));
        $locationId = $this->client->getLocationId();
        // Prefers an explicit staff-set value (Manage Service's Inventory &
        // Pricing tab's real Variants switch) over the previous
        // rentals-count-based guess — falls back to that guess only when
        // the caller genuinely didn't send one (e.g. a save from a form
        // that predates this field, or any other caller of this method).
        $isVariantsEnabled = array_key_exists('is_variants_enabled', $incoming)
            ? (bool) $incoming['is_variants_enabled']
            : ($rental->is_variants_enabled ?? $product->rentals()->count() > 1);
        // Same explicit-value-first pattern — has_quantity_enabled is the
        // single source of truth for whether this row tracks stock at all,
        // replacing the previous `$quantity > 1` guess this push used to
        // make (a guess against the *listing-wide* Quantity field, not this
        // specific row's own tracked value).
        $hasQuantityEnabled = array_key_exists('has_quantity_enabled', $incoming)
            ? (bool) $incoming['has_quantity_enabled']
            : ($rental->has_quantity_enabled ?? $quantity > 1);

        return [
            'industryType' => self::RENTAL_INDUSTRY,
            'name' => $name,
            'slug' => $incoming['slug'] ?? $product->slug,
            'description' => $description,
            'hideDescription' => false,
            'coverImage' => $this->imageSync->pushImageToGhl($product),
            'isActive' => $isActive,
            'locationId' => $locationId,
            'isVariantsEnabled' => $isVariantsEnabled,
            'payment' => [
                'amount' => $listingPrice,
                'description' => $description,
            ],
            'bookingUnit' => $serviceDurationUnit,
            'useCustomForm' => false,
            'formId' => '',
            'quantity' => $quantity,
            'hasQuantityEnabled' => $hasQuantityEnabled,
            'images' => $this->resolveServiceImagesForGhl($product),
            'preBuffer' => $bookingSettings['preBuffer'] ?? null,
            'preBufferUnit' => $bookingSettings['preBufferUnit'] ?? 'min',
            'postBuffer' => $bookingSettings['postBuffer'] ?? null,
            'postBufferUnit' => $bookingSettings['postBufferUnit'] ?? 'min',
            'minDuration' => $bookingSettings['minDuration'] ?? null,
            'minDurationUnit' => $bookingSettings['minDurationUnit'] ?? 'day',
            'maxDuration' => $bookingSettings['maxDuration'] ?? null,
            'maxDurationUnit' => $bookingSettings['maxDurationUnit'] ?? 'day',
            'bookingPeriodType' => $bookingPeriodType,
            'hasTimeSelection' => $bookingSettings['hasTimeSelection'] ?? true,
            'bookingStartTime' => $bookingSettings['bookingStartTime'] ?? null,
            'bookingEndTime' => $bookingSettings['bookingEndTime'] ?? null,
            'serviceDurations' => collect($bookingSettings['serviceDurations'] ?? [])->map(fn ($d) => [
                'duration' => $d['duration'] ?? null,
                'durationUnit' => $d['durationUnit'] ?? null,
            ])->values()->all(),
            'allowBookingAfter' => $bookingSettings['allowBookingAfter'] ?? null,
            'allowBookingAfterUnit' => $bookingSettings['allowBookingAfterUnit'] ?? 'day',
            'allowBookingFor' => $bookingSettings['allowBookingFor'] ?? null,
            'allowBookingForUnit' => $bookingSettings['allowBookingForUnit'] ?? 'day',
            'pricingRule' => [
                'name' => "{$name} Pricing",
                'locationId' => $locationId,
                'targetId' => $rental->ghl_id,
                'appliesTo' => 'service',
                'priority' => 1,
                'basePrice' => [
                    'value' => $listingPrice,
                    'strategy' => 'per_day',
                ],
                'paymentTerms' => ['type' => 'full'],
                'rules' => [],
            ],
            'serviceCategoryId' => $serviceCategoryId,
            'serviceDurationUnit' => $serviceDurationUnit,
            'teamMembers' => [],
        ];
    }

    /**
     * The outbound `images[]` array — every already-GHL-hosted image
     * (a real http(s) URL, whether Lead Connector's own CDN or an external
     * one) is sent as-is; a purely local (`/storage/...`) non-cover image
     * is skipped (logged, not silently dropped without a trace) rather than
     * attempting a per-image CDN upload this pass doesn't build — the
     * cover image (position:0) is always covered correctly regardless,
     * since it's resolved through the same GhlImageSyncService::
     * pushImageToGhl() already used for `coverImage` above.
     */
    private function resolveServiceImagesForGhl(EngageProduct $product): array
    {
        // pushImageToGhl() (already called for `coverImage` above) leaves a
        // real Lead Connector CDN URL in ghl_image_url only when it actually
        // succeeded — a null/local-path fallback here means there's nothing
        // usable to substitute for position:0, so it falls through to the
        // exact same "skip a non-http local image" handling every other
        // position already gets, rather than ever sending a raw local path.
        $coverUrl = $product->ghl_image_url && str_starts_with($product->ghl_image_url, 'http')
            ? $product->ghl_image_url
            : null;
        $images = [];

        foreach ($product->images ?? [] as $img) {
            $url = $img['url'] ?? null;
            $position = $img['position'] ?? 0;

            if ($position === 0 && $coverUrl) {
                $url = $coverUrl;
            }

            if (! $url || ! str_starts_with($url, 'http')) {
                if ($url) {
                    Log::warning('GHL service update: skipping local image not yet uploaded to Lead Connector', [
                        'product_id' => $product->id,
                        'position' => $position,
                    ]);
                }

                continue;
            }

            $images[] = [
                'url' => $url,
                'name' => $img['name'] ?? 'Image '.($position + 1),
                'position' => $position,
            ];
        }

        return $images;
    }

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

        // Delete-sync for whole listings, on top of finalizeListing()'s
        // pre-existing per-variant pruning above. $bases (not $baseDetails)
        // is deliberately the source of "seen" ids — it's every top-level
        // listing this pull's single `calendars/services` call returned,
        // independent of whether that listing's own *detail* fetch
        // succeeded; a listing whose detail fetch merely failed this run is
        // still very much present in GHL and must never be archived for
        // that reason alone. Wrapped in its own try/catch so a bug here can
        // never take down an otherwise-successful pull (matches this
        // method's existing per-listing error-isolation).
        $archivedListings = 0;
        try {
            $seenBaseGhlIds = $bases->pluck('_id')->filter()->values()->all();
            $archivedListings = $this->archiveMissingRentalListings($tenantId, $seenBaseGhlIds);
        } catch (\Exception $e) {
            $errors[] = ['service_id' => null, 'name' => null, 'error' => 'Rental listing delete-sync failed: '.$e->getMessage()];
            Log::error('GHL rental listing delete-sync failed', ['engage_organization_location_id' => $tenantId, 'error' => $e->getMessage()]);
        }

        return [
            'pulled' => $pulled,
            'base_listings_pulled' => $baseListingsPulled,
            'variants_pulled' => $variantsPulled,
            'archived_listings' => $archivedListings,
            'errors' => count($errors),
            'error_details' => $errors,
        ];
    }

    /**
     * Delete-sync: a rental listing (a Product with product_rental_id set)
     * whose GHL base service id no longer appears at all in this pull's
     * top-level listing list is soft-deleted — Product already uses
     * SoftDeletes, so this is fully reversible and never breaks a
     * Booking/RentalTransaction foreign key that still points at it (soft
     * delete hides the row from default queries without removing it or the
     * data anything else references). Every ProductRental row under that
     * listing (base + every variant) is also marked is_active=false and
     * forgotten from GhlRentalGateway's live-detail cache, for defense in
     * depth against any code path that queries ProductRental directly
     * rather than through its (now soft-deleted, hidden-by-default) Product
     * — upsertBaseListing()'s own restore step above is what undoes this if
     * GHL brings the listing back on a later pull.
     *
     * Guarded on a non-empty $seenBaseGhlIds — see
     * GhlProductSyncService::deactivateMissingCategories()'s doc comment
     * for why a successful-but-empty response must never be trusted as
     * "GHL now has zero rental listings."
     */
    private function archiveMissingRentalListings(string $tenantId, array $seenBaseGhlIds): int
    {
        if (empty($seenBaseGhlIds)) {
            return 0;
        }

        $goneBaseRentals = EngageProductRental::whereHas(
            'product',
            fn ($q) => $q->where('engage_organization_location_id', $tenantId)
        )
            ->whereNotNull('ghl_id')
            ->whereColumn('ghl_id', 'service_id')
            ->whereNotIn('ghl_id', $seenBaseGhlIds)
            ->get();

        $archivedCount = 0;

        foreach ($goneBaseRentals as $baseRental) {
            $product = EngageProduct::withTrashed()->find($baseRental->product_id);

            if (! $product || $product->trashed()) {
                continue;
            }

            $product->delete();
            $archivedCount++;

            EngageProductRental::where('product_id', $product->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $siblingGhlIds = EngageProductRental::where('product_id', $product->id)
                ->whereNotNull('ghl_id')
                ->pluck('ghl_id');

            foreach ($siblingGhlIds as $ghlId) {
                $this->gateway->forget($ghlId);
            }
        }

        return $archivedCount;
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
        $results = ['pulled' => 0, 'created' => 0, 'errors' => 0, 'deleted' => 0, 'error_details' => [], 'note' => null];

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
            $seenGhlIds = [];

            foreach ($parsed['items'] as $ghlCategory) {
                $ghlId = $ghlCategory['_id'] ?? $ghlCategory['id'] ?? null;

                // A category GHL has soft-deleted no longer really exists —
                // skip it entirely rather than importing/updating a local
                // row for it (matches finalizeListing()'s own treatment of
                // rentals GHL no longer returns), and deliberately do NOT
                // add it to $seenGhlIds below — an already-local row for
                // this id must be treated as "gone" too (see
                // deleteMissingServiceCategories()).
                if (! $ghlId || ($ghlCategory['deleted'] ?? false) === true) {
                    $skippedDeleted++;

                    continue;
                }

                $seenGhlIds[] = $ghlId;

                $data = [
                    'name' => $ghlCategory['name'] ?? 'Untitled',
                    'is_active' => $ghlCategory['isActive'] ?? true,
                    'engage_sync_status' => 'synced',
                    'engage_last_synced_at' => now(),
                    'engage_organization_location_id' => $tenantId,
                ];

                $category = EngageProductRentalCategory::where('engage_organization_location_id', $tenantId)
                    ->where('ghl_category_id', $ghlId)
                    ->first();

                // A category created locally (via ServiceCategoryController::store())
                // may not have made it to GHL yet — e.g. the outbound push in
                // syncServiceCategoryToGhl() failed, or is still `pending` — while
                // the *same* category was independently created directly in GHL and
                // is only now being pulled in for the first time. Without this,
                // that would create a true local duplicate (two rows, same name,
                // only one ever getting a ghl_category_id). Link by an exact,
                // case-insensitive name match against an as-yet-unlinked local row
                // instead of creating a second one — mirrors the same
                // duplicate-avoidance reasoning used on the push side (see
                // syncServiceCategoryToGhl()'s own name-matching before POSTing).
                if (! $category) {
                    $category = EngageProductRentalCategory::where('engage_organization_location_id', $tenantId)
                        ->whereNull('ghl_category_id')
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
                        ->first();
                }

                if ($category) {
                    $category->update($data + ['ghl_category_id' => $ghlId]);
                } else {
                    $category = EngageProductRentalCategory::create($data + ['ghl_category_id' => $ghlId]);
                    $results['created']++;
                }

                // Each service-category item also carries `industryType`
                // ('pos'/'rental') and, when GHL considers this category the
                // rental-side counterpart of a regular Category, an
                // `associationId` matching that Category's
                // engage_collection_id. Only ever touches a Category already
                // known here (never creates one) and only within this same
                // tenant — engage_collection_id isn't unique across
                // organizations on its own, so the tenant scope is what
                // keeps one org's pull from ever touching another org's row.
                $associationId = $ghlCategory['associationId'] ?? null;

                if ($associationId) {
                    EngageCategory::where('engage_organization_location_id', $tenantId)
                        ->where('engage_collection_id', $associationId)
                        ->update([
                            'industry_type' => $ghlCategory['industryType'] ?? EngageCategory::INDUSTRY_TYPE_RENTAL,
                            'rental_category_id' => $category->id,
                        ]);
                }

                $results['pulled']++;
            }

            // Only when the response shape was actually recognized — an
            // unrecognized shape must never trigger deletion (that's a
            // parsing gap, not real GHL data). $parsed['items'] (the raw
            // count GHL actually returned, before the deleted-skip filter
            // above) — not count($seenGhlIds) — is what gates this: a
            // response of "here are 3 categories, all marked deleted" is
            // real, trustworthy GHL data (delete all 3 locally, even
            // though $seenGhlIds ends up empty), completely different from
            // "GHL returned 0 categories total" (the suspiciously-empty
            // case every other delete-sync method in this codebase guards
            // against — see deactivateMissingCategories()'s doc comment).
            if ($parsed['recognized'] && count($parsed['items']) > 0) {
                $results['deleted'] = $this->deleteMissingServiceCategories($tenantId, $seenGhlIds);
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
                        'engage_organization_location_id' => $tenantId,
                        'top_level_keys' => array_keys($response),
                    ]);
                } elseif ($skippedDeleted > 0) {
                    $results['note'] = "GHL returned {$skippedDeleted} service categor".($skippedDeleted === 1 ? 'y' : 'ies')
                        .' for this location, but all of them are marked deleted — 0 usable categories were pulled.';
                    Log::info('GHL service-category pull: every returned category was deleted', [
                        'engage_organization_location_id' => $tenantId,
                        'deleted_count' => $skippedDeleted,
                    ]);
                } else {
                    $results['note'] = 'GHL returned an empty service-category list for this location — no categories are configured there yet.';
                    Log::info('GHL service-category pull: location has zero categories configured', ['engage_organization_location_id' => $tenantId]);
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

    /**
     * Delete-sync: a ServiceCategory previously pulled from GHL
     * (ghl_category_id set) that no longer appears — genuinely absent, or
     * present but marked `deleted: true` — in this pull's category list is
     * permanently deleted from the local database.
     *
     * **2026-08-12 revision, user-directed**: this originally deactivated
     * (`is_active=false`) rather than hard-deleted, matching every other
     * entity's delete-sync in this codebase (Contacts/Products/Categories/
     * Rentals all soft-delete or deactivate) — reported live as confusing
     * ("why does a category that's gone from GHL still show up in the
     * list?"), and the user explicitly chose real deletion over hiding
     * inactive rows by default. This is safe specifically for
     * ServiceCategory: a hard DELETE here does **not** leave a dangling
     * reference anywhere — `EngageProductRentalCategory::rentals()` is keyed on the raw
     * `ghl_category_id` string, not a real FK, so a `ProductRental` still
     * pointing at a since-deleted category simply resolves that relation to
     * `null` (falls back to "no category"), exactly the same as manually
     * deleting a category via `ServiceCategoryController::destroy()`
     * already does today. This one-directional change is scoped to
     * ServiceCategory only — Contacts/Products/Categories/Rentals keep
     * their existing soft-delete/deactivate behavior, unchanged, since only
     * ServiceCategory's UX was reported as a problem.
     *
     * A category that reappears in GHL after being deleted here is simply
     * created fresh on the next pull (or re-linked by name if a local-only
     * row with the same name exists — see the name-match fallback above) —
     * no restore-from-trash step is needed since there's no soft-delete
     * state to restore from.
     *
     * Only ever touches rows that are themselves GHL-sourced
     * (ghl_category_id IS NOT NULL) — a locally-created service category is
     * never touched by this method.
     *
     * The caller gates this on `count($parsed['items']) > 0` (GHL actually
     * returned real data this run — even if every item is marked deleted),
     * not on `$seenGhlIds` being non-empty — those aren't the same
     * condition here, unlike every other delete-sync method in this
     * codebase: a response of "N categories, all deleted" legitimately
     * produces an empty $seenGhlIds while still being fully trustworthy
     * data, so this method itself does not re-guard on that emptiness (see
     * GhlProductSyncService::deactivateMissingCategories()'s doc comment
     * for the general "never trust a suspiciously-empty response" reasoning
     * this codebase otherwise follows everywhere else).
     */
    private function deleteMissingServiceCategories(string $tenantId, array $seenGhlIds): int
    {
        return EngageProductRentalCategory::where('engage_organization_location_id', $tenantId)
            ->whereNotNull('ghl_category_id')
            ->whereNotIn('ghl_category_id', $seenGhlIds)
            ->delete();
    }

    /**
     * Push-sync (local -> GHL), the counterpart to pullServiceCategories()
     * above. Creates via POST when the category has never been linked,
     * updates via PUT `calendars/service-categories/{id}` when it has.
     *
     * **The real request shape, live-verified end to end 2026-08-12, not
     * guessed**: an earlier version of this method returned a real `401
     * "not authorized for this scope"` on every write attempt, which was
     * originally (and, it turns out, incorrectly) diagnosed as a missing
     * OAuth scope. The user supplied real captured requests from GHL's own
     * dashboard UI showing the actual shape — confirmed live against this
     * tenant's connection using the *same, already-granted* token (no
     * re-authorization involved): a full create -> appear-on-pull -> delete
     * round trip was run with a disposable, clearly-named test category and
     * cleaned up after. Two asymmetric quirks, both real, both confirmed by
     * the API's own validation error messages when they were missing/wrong:
     *   - `industryType=rental` must be sent as a **query param** on every
     *     write call (POST/PUT/DELETE), not just GET — omitting it is what
     *     actually produced the original 401s, not a scope problem at all.
     *   - POST requires `locationId` in the body (`422 "locationId can't be
     *     undefined"` without it); PUT rejects that exact same field
     *     (`422 "property locationId should not exist"` with it) — an
     *     asymmetry easy to miss without testing both verbs for real.
     *   - Both also require `slug` in the body — GHL does not derive one
     *     from `name` automatically the way `products/collections` does.
     * Response shape: the created/updated record comes back nested under a
     * `serviceCategory` key (`{"serviceCategory": {"_id": ..., ...}}`), not
     * at the top level.
     *
     * Deliberately non-blocking regardless: this method still throws on
     * failure (matching syncCategoryToGhl()'s own contract) and leaves
     * `engage_sync_status='error'` on the row, but the caller
     * (ServiceCategoryController) always keeps the already-made local
     * change — any future GHL-side failure (network, a real permission
     * change, etc.) must never make local Service Category CRUD stop
     * working, exactly the same reasoning as before, just no longer
     * describing today's actual, working default path.
     */
    public function syncServiceCategoryToGhl(EngageProductRentalCategory $category): EngageProductRentalCategory
    {
        $category->update(['engage_sync_status' => 'pending']);

        $locationId = $this->client->getLocationId();
        $slug = Str::slug($category->name);
        $query = ['industryType' => self::RENTAL_INDUSTRY];

        try {
            $ghlId = $category->ghl_category_id ?: $this->findGhlServiceCategoryIdByName($category->name);

            if ($ghlId) {
                $response = $this->client->put("calendars/service-categories/{$ghlId}", [
                    'name' => $category->name,
                    'slug' => $slug,
                    'industryType' => self::RENTAL_INDUSTRY,
                ], $query, self::SERVICE_CATEGORIES_API_VERSION);
            } else {
                $response = $this->client->post('calendars/service-categories', [
                    'name' => $category->name,
                    'slug' => $slug,
                    'locationId' => $locationId,
                    'industryType' => self::RENTAL_INDUSTRY,
                ], $query, self::SERVICE_CATEGORIES_API_VERSION);
            }

            Log::info('GHL service-category sync response', ['response' => $response]);

            $resolvedId = $ghlId ?: ($response['serviceCategory']['_id'] ?? $response['_id'] ?? $response['id'] ?? null);

            $category->update([
                'ghl_category_id' => $resolvedId,
                'engage_sync_status' => 'synced',
                'engage_last_synced_at' => now(),
            ]);

            return $category->fresh();
        } catch (\Exception $e) {
            Log::error('GHL service-category sync failed', [
                'service_category_id' => $category->id,
                'error' => $e->getMessage(),
            ]);

            $category->update(['engage_sync_status' => 'error']);

            throw $e;
        }
    }

    /**
     * Mirrors GhlProductSyncService::deleteCategoryFromGhl(). `industryType`
     * as a query param — not `altId`/`altType`/`locationId`, which is what
     * `products/collections`'s own DELETE needs — is what this endpoint
     * actually requires; confirmed by a real live delete against a
     * disposable test category created and cleaned up for exactly this
     * check (see syncServiceCategoryToGhl()'s own doc comment for the full
     * investigation).
     */
    public function deleteServiceCategoryFromGhl(EngageProductRentalCategory $category): void
    {
        if (! $category->ghl_category_id) {
            return;
        }

        try {
            $this->client->delete("calendars/service-categories/{$category->ghl_category_id}", [
                'industryType' => self::RENTAL_INDUSTRY,
            ], self::SERVICE_CATEGORIES_API_VERSION);
        } catch (\Exception $e) {
            Log::error('GHL service-category delete failed', [
                'service_category_id' => $category->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Duplicate-avoidance for syncServiceCategoryToGhl()'s create path: a
     * category with the same name may already exist in GHL (created there
     * directly, not yet pulled locally) — link to it via PUT instead of
     * blindly POSTing a second, duplicate category with the same name.
     * Mirrors this same "find by name before creating" precedent already
     * used on the pull side (see pullServiceCategories()'s own name-match
     * fallback) and the find-or-create idiom
     * GhlProductSyncService::syncDefaultPriceToGhl() already uses for
     * prices. Best-effort: a failed lookup here degrades to "just create
     * it" rather than blocking the whole sync attempt.
     */
    private function findGhlServiceCategoryIdByName(string $name): ?string
    {
        try {
            $response = $this->client->get('calendars/service-categories', [
                'locationId' => $this->client->getLocationId(),
                'industryType' => self::RENTAL_INDUSTRY,
            ], self::SERVICE_CATEGORIES_API_VERSION);

            $parsed = $this->parseServiceCategories($response);
            $needle = mb_strtolower(trim($name));

            foreach ($parsed['items'] as $item) {
                if (($item['deleted'] ?? false) === true) {
                    continue;
                }

                if (mb_strtolower(trim($item['name'] ?? '')) === $needle) {
                    return $item['_id'] ?? $item['id'] ?? null;
                }
            }
        } catch (\Exception $e) {
            Log::warning('GHL service-category name lookup failed (falling back to create)', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function serviceDetailRequest(string $ghlId, string $locationId): array
    {
        return [
            'endpoint' => "calendars/services/{$ghlId}",
            'query' => ['locationId' => $locationId, 'industryType' => self::RENTAL_INDUSTRY],
        ];
    }

    /** Identity lives on product_rentals.ghl_id — resolve the Product through it. */
    private function upsertBaseListing(GhlServiceDetail $detail, string $tenantId): EngageProduct
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
            // The real, always-in-sync mirror of Lead Connector's own
            // isActive flag (see the migration's doc comment) — status
            // above is kept alongside it for every pre-existing status-based
            // query/filter, but is_active is what Manage Service's edit form
            // actually reads/writes for a rental now.
            'is_active' => $detail->isActive(),
            // `images` alone is sufficient — `image` is a computed
            // accessor derived from images[0] (position:0), see
            // EngageProduct::image(). imagesForPersistence() also folds in
            // the coverImage-only fallback for a service GHL sent with no
            // images array at all.
            'images' => $detail->imagesForPersistence(),
            'ghl_product_id' => $detail->paymentsProductId(),
            'quantity' => $detail->quantity(),
            'price' => $detail->basePrice() ?? $detail->paymentAmount(),
            'engage_sync_status' => 'synced',
            'engage_last_synced_at' => now(),
            'engage_organization_location_id' => $tenantId,
        ];

        // product_rentals has no location column of its own — scoped via
        // its product relationship instead (including trashed products, so
        // an archived listing's base rental row is still found here and can
        // be restored below).
        $existing = EngageProductRental::whereHas(
            'product',
            fn ($q) => $q->withTrashed()->where('engage_organization_location_id', $tenantId)
        )->where('ghl_id', $ghlId)->first();

        if ($existing) {
            // $existing->product resolves to null when the product was
            // soft-deleted by a prior delete-sync run
            // (archiveMissingRentalListings() below) — Product's SoftDeletes
            // global scope hides it from the relation by default. GHL
            // still/again has this listing, so it must be restored rather
            // than left permanently hidden (or, worse, calling update() on
            // null a line below). The correct is_active state for the base
            // row (and every variant) is written moments later by this same
            // pull's own upsertRentalRow()/finalizeListing() calls, so
            // nothing else needs forcing here.
            $product = $existing->product ?? EngageProduct::withTrashed()->find($existing->product_id);

            if ($product) {
                if ($product->trashed()) {
                    $product->restore();
                }
                $product->update($productAttributes);
            } else {
                $product = EngageProduct::create($productAttributes);
            }
        } else {
            $product = EngageProduct::create($productAttributes);
        }

        $rental = $this->upsertRentalRow($detail, $product, $ghlId, $tenantId);

        return $product->fresh();
    }

    private function upsertVariant(GhlServiceDetail $detail, EngageProduct $baseProduct, string $baseGhlId, string $tenantId): EngageProductRental
    {
        $ghlId = $detail->id();

        if (! $ghlId) {
            throw new \RuntimeException('GHL variant detail missing _id');
        }

        return $this->upsertRentalRow($detail, $baseProduct, $baseGhlId, $tenantId);
    }

    private function upsertRentalRow(GhlServiceDetail $detail, EngageProduct $product, string $baseGhlId, string $tenantId): EngageProductRental
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
        // Matched by (product_id, ghl_id) — product_rentals has no location
        // column of its own (dropped entirely by
        // 2026_07_30_000007_rename_tenant_id_to_engage_organization_location_id.php);
        // it inherits location via product_id -> products.
        // engage_organization_location_id, and product_rentals_product_id_ghl_id_unique
        // is the real unique constraint that migration re-established.
        return EngageProductRental::updateOrCreate(
            ['product_id' => $product->id, 'ghl_id' => $detail->id()],
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
                // Booking Settings tab (2026-08-21) — written per the exact
                // GHL service id being upserted (base or variant), same as
                // every other per-row field above; the edit form itself only
                // ever surfaces/edits the base rental's copy (see
                // ProductService::update()), matching how listing_price's
                // own editable-on-base-only convention already works.
                'booking_period_type' => $detail->bookingPeriodType(),
                'booking_settings' => $detail->bookingSettingsForPersistence(),
                // Inventory & Pricing tab (2026-08-21) — written per the
                // exact GHL service id being upserted (base or variant), same
                // convention as booking_period_type/booking_settings above:
                // each variant is its own full GHL service record with its
                // own quantity/pricingRule, so this keeps every row's Stock
                // and Advanced Pricing rules fresh on every pull. quantity
                // is deliberately NOT wrapped in array_filter's null-check
                // exemption the way booking_settings/pricing_rules are (both
                // always arrays, so array_filter's `!== null` check never
                // drops them) — a real transition from a tracked number back
                // to "unlimited" (null) won't overwrite an existing value
                // here, matching this method's pre-existing behavior for
                // every other nullable scalar field above.
                'quantity' => $detail->quantity(),
                'pricing_rules' => $detail->pricingRulesForPersistence(),
                // The real Lead Connector flag deciding whether this exact
                // row tracks stock at all — the single source of truth for
                // the Inventory & Pricing tab's "Inventory" switch. A plain
                // boolean, not wrapped in the null-check exemption above,
                // but that's fine here: hasQuantityEnabled() itself never
                // returns null (defaults false), so it's never dropped by
                // array_filter's `!== null` check regardless.
                'has_quantity_enabled' => $detail->hasQuantityEnabled(),
            ], fn ($value) => $value !== null)
        );
    }

    /**
     * After variants are synced: pin listing snapshot to the GHL base service
     * (variantId = null) — default rental pointer, price, and product fields.
     */
    private function finalizeListing(EngageProduct $product, array $seenGhlIds, GhlServiceDetail $baseDetail, string $baseGhlId): void
    {
        $baseRental = EngageProductRental::where('product_id', $product->id)
            ->where('ghl_id', $baseGhlId)
            ->where('service_id', $baseGhlId)
            ->first();

        $basePrice = $baseDetail->basePrice() ?? $baseDetail->paymentAmount();

        $listingUpdate = array_filter([
            'name' => $baseDetail->name(),
            'description' => $baseDetail->description(),
            // `image` is a computed accessor derived from images[0]
            // (position:0), see EngageProduct::image() — only `images`
            // needs writing.
            'images' => $baseDetail->imagesForPersistence(),
            'ghl_product_id' => $baseDetail->paymentsProductId(),
            'quantity' => $baseDetail->quantity(),
            'price' => $basePrice,
            'product_rental_id' => $baseRental?->id,
        ], fn ($value) => $value !== null);

        if ($listingUpdate !== []) {
            $product->update($listingUpdate);
        }

        if ($baseRental) {
            // isVariantsEnabled always sourced from the BASE listing's own
            // detail response (`$baseDetail`) — a variant's own detail
            // response carries this same field but it is NOT authoritative
            // there (confirmed false on a real variant's own detail even
            // when the base correctly says true) — never read it off a
            // variant's own GhlServiceDetail.
            $baseRental->update(array_filter([
                'listing_price' => $basePrice,
                'is_variants_enabled' => $baseDetail->isVariantsEnabled(),
            ], fn ($value) => $value !== null));
        }

        $pruned = EngageProductRental::where('product_id', $product->id)
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
