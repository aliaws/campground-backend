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
            Log::error('GHL rental listing delete-sync failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
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

        $goneBaseRentals = ProductRental::where('tenant_id', $tenantId)
            ->whereNotNull('ghl_id')
            ->whereColumn('ghl_id', 'service_id')
            ->whereNotIn('ghl_id', $seenBaseGhlIds)
            ->get();

        $archivedCount = 0;

        foreach ($goneBaseRentals as $baseRental) {
            $product = Product::withTrashed()->find($baseRental->product_id);

            if (! $product || $product->trashed()) {
                continue;
            }

            $product->delete();
            $archivedCount++;

            ProductRental::where('product_id', $product->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $siblingGhlIds = ProductRental::where('product_id', $product->id)
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
        $results = ['pulled' => 0, 'created' => 0, 'errors' => 0, 'deactivated' => 0, 'error_details' => [], 'note' => null];

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
                // deactivateMissingServiceCategories()).
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
                    'tenant_id' => $tenantId,
                ];

                $category = ServiceCategory::where('tenant_id', $tenantId)
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
                    $category = ServiceCategory::where('tenant_id', $tenantId)
                        ->whereNull('ghl_category_id')
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
                        ->first();
                }

                if ($category) {
                    $category->update($data + ['ghl_category_id' => $ghlId]);
                } else {
                    ServiceCategory::create($data + ['ghl_category_id' => $ghlId]);
                    $results['created']++;
                }

                $results['pulled']++;
            }

            // Only when the response shape was actually recognized — an
            // unrecognized shape must never trigger deactivation (that's a
            // parsing gap, not real GHL data). $parsed['items'] (the raw
            // count GHL actually returned, before the deleted-skip filter
            // above) — not count($seenGhlIds) — is what gates this: a
            // response of "here are 3 categories, all marked deleted" is
            // real, trustworthy GHL data (deactivate all 3 locally, even
            // though $seenGhlIds ends up empty), completely different from
            // "GHL returned 0 categories total" (the suspiciously-empty
            // case every other delete-sync method in this codebase guards
            // against — see deactivateMissingCategories()'s doc comment).
            if ($parsed['recognized'] && count($parsed['items']) > 0) {
                $results['deactivated'] = $this->deactivateMissingServiceCategories($tenantId, $seenGhlIds);
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

    /**
     * Delete-sync: a ServiceCategory previously pulled from GHL
     * (ghl_category_id set) that no longer appears — genuinely absent, or
     * present but marked `deleted: true` — in this pull's category list is
     * deactivated (is_active=false), never hard-deleted. ServiceCategory
     * has no SoftDeletes trait, and a hard DELETE would leave any
     * ProductRental row still pointing at that raw ghl_category_id with a
     * dangling reference (ServiceCategory::rentals() is keyed on the raw
     * GHL id, not a real FK) — deactivation is safe and fully reversible,
     * since a later pull's own upsert always re-writes `is_active` from
     * live GHL data the moment the category reappears (see the loop above).
     *
     * Only ever touches rows that are themselves GHL-sourced
     * (ghl_category_id IS NOT NULL) — a locally-created service category
     * (if one ever exists) is never touched by this method.
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
    private function deactivateMissingServiceCategories(string $tenantId, array $seenGhlIds): int
    {
        return ServiceCategory::where('tenant_id', $tenantId)
            ->whereNotNull('ghl_category_id')
            ->whereNotIn('ghl_category_id', $seenGhlIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * Push-sync (local -> GHL), the counterpart to pullServiceCategories()
     * above. Creates via POST when the category has never been linked,
     * updates via PUT `calendars/service-categories/{id}` when it has —
     * same create-or-update shape as GhlProductSyncService::syncCategoryToGhl().
     *
     * **Live-verified limitation, not a guess**: a real call against this
     * tenant's actual GHL connection confirmed `GET calendars/service-categories`
     * works (see pullServiceCategories()'s own doc comment — the
     * `calendars/groups.readonly` scope fix), but POST/PUT/DELETE against
     * the same path currently return a real `401 "The token is not
     * authorized for this scope"` — a write-scope gap, not a wrong endpoint.
     * `calendars/groups.write` (added to GhlAuthService::DEFAULT_SCOPES
     * alongside its readonly sibling) is the best-informed next candidate,
     * unconfirmed until the connection is re-authorized (Engage Settings ->
     * Authorize) and re-tested — a token refresh alone cannot add scopes to
     * an existing grant.
     *
     * A second real, live-tested hypothesis was ruled out during this
     * investigation: `calendars/service-categories`' own `associationId`
     * field points at a `products/collections` id (confirmed by cross-
     * referencing this tenant's real "Cabins" category against its real
     * collections list) — but POSTing a brand-new test collection via the
     * already-write-scoped `products/collections` endpoint did NOT cause it
     * to appear as a service category on a follow-up pull, so collection
     * creation alone doesn't establish whatever the real association
     * mechanism is. That test collection was deleted immediately after
     * (confirmed removed) — this method deliberately does NOT write through
     * `products/collections` as a workaround, since the real linkage is
     * unconfirmed and guessing wrong here risks creating an orphaned
     * collection with no known relationship to any rental category.
     *
     * Deliberately non-blocking either way: this method throws on failure
     * (matching syncCategoryToGhl()'s own contract) and leaves
     * `engage_sync_status='error'` on the row, but the caller
     * (ServiceCategoryController) always keeps the already-made local
     * change — a write-scope 401 on GHL's side must never make local
     * Service Category CRUD (which worked standalone before this feature)
     * stop working.
     */
    public function syncServiceCategoryToGhl(ServiceCategory $category): ServiceCategory
    {
        $category->update(['engage_sync_status' => 'pending']);

        $locationId = $this->client->getLocationId();

        $payload = [
            'name' => $category->name,
            'locationId' => $locationId,
            'isActive' => (bool) $category->is_active,
            'industryType' => self::RENTAL_INDUSTRY,
        ];

        try {
            $ghlId = $category->ghl_category_id ?: $this->findGhlServiceCategoryIdByName($category->name);

            $response = $ghlId
                ? $this->client->put("calendars/service-categories/{$ghlId}", $payload, [], self::SERVICE_CATEGORIES_API_VERSION)
                : $this->client->post('calendars/service-categories', $payload, [], self::SERVICE_CATEGORIES_API_VERSION);

            Log::info('GHL service-category sync response', ['response' => $response]);

            $resolvedId = $ghlId ?: ($response['_id'] ?? $response['id'] ?? $response['serviceCategory']['_id'] ?? null);

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
     * Mirrors GhlProductSyncService::deleteCategoryFromGhl() — no-ops when
     * the category was never linked (nothing to delete on the GHL side).
     * `altId`/`altType` are passed as query params on the DELETE call, not
     * assumed — confirmed necessary via a real live test against this
     * tenant's connection (a bare DELETE with no query params returned a
     * real `422 "locationId/altId must be a string and it should exists"`).
     */
    public function deleteServiceCategoryFromGhl(ServiceCategory $category): void
    {
        if (! $category->ghl_category_id) {
            return;
        }

        $locationId = $this->client->getLocationId();

        try {
            $this->client->delete("calendars/service-categories/{$category->ghl_category_id}", [
                'altId' => $locationId,
                'altType' => 'location',
                'locationId' => $locationId,
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
            $product = $existing->product ?? Product::withTrashed()->find($existing->product_id);

            if ($product) {
                if ($product->trashed()) {
                    $product->restore();
                }
                $product->update($productAttributes);
            } else {
                $product = Product::create($productAttributes);
            }
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
