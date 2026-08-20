<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\EngageCategory;
use App\Models\EngageProduct;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Syncs PHYSICAL/DIGITAL goods to GHL's Payments Products API.
 * Rental listings are excluded — they sync via GhlServiceSyncService only.
 */
class GhlProductSyncService
{
    public function __construct(
        private GhlClient $client,
        private GhlServiceSyncService $serviceSync,
        private ProductService $productService,
    ) {}

    public function syncProductToGhl(EngageProduct $product): EngageProduct
    {
        if ($product->isRental()) {
            throw new \RuntimeException('Rental listings are synced via Services pull, not product sync.');
        }

        $product->update(['engage_sync_status' => 'pending']);

        $locationId = $this->client->getLocationId();

        $ghlProductType = match ($product->product_type) {
            'SERVICE' => 'SERVICE',
            'DIGITAL' => 'DIGITAL',
            default => 'PHYSICAL',
        };

        $payload = [
            'altId' => $locationId,
            'altType' => 'location',
            'locationId' => $locationId,
            'name' => $product->name,
            'description' => $product->description ?? '',
            'productType' => $ghlProductType,
            'status' => $product->status ?? 'active',
            'availableInStore' => $product->available_in_store ?? true,
            // Always false regardless of the local `is_taxes_enabled` flag —
            // GHL's live API rejects `isTaxesEnabled: true` with 422
            // "taxes should not be empty" unless a non-empty `taxes` array of
            // real GHL tax-category ids is also sent, and this app has no
            // tax-category management feature to source those ids from.
            // Sending the local flag verbatim broke sync for every product
            // that had it checked (confirmed live against GHL).
            'isTaxesEnabled' => false,
            'taxInclusive' => $product->tax_inclusive ?? false,
            'trackProductInventory' => $product->track_product_inventory ?? false,
        ];

        if ($product->slug) {
            $payload['slug'] = $product->slug;
        }

        $imageUrl = $this->uploadImageToGhl($product);
        if ($imageUrl) {
            $payload['image'] = $imageUrl;
        }

        $categoryIds = $product->categories()->pluck('engage_collection_id')->filter()->values()->toArray();
        if (! empty($categoryIds)) {
            $payload['collectionIds'] = $categoryIds;
        }

        try {
            $isNew = ! $product->ghl_product_id;

            $response = $isNew
                ? $this->client->post('products/', $payload)
                : $this->client->put("products/{$product->ghl_product_id}", $payload);

            Log::info('GHL product sync response', ['product' => $product->name, 'response' => $response]);

            $ghlId = $this->extractId($response, $product->ghl_product_id);

            $product->update([
                'ghl_product_id' => $ghlId,
                'engage_sync_status' => 'synced',
                'engage_last_synced_at' => now(),
            ]);

            $this->syncDefaultPriceToGhl($product->fresh());

            return $product->fresh();
        } catch (\Exception $e) {
            Log::error('GHL product sync failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            $product->update(['engage_sync_status' => 'error']);

            throw $e;
        }
    }

    public function pullFromGhl(EngageProduct $product): EngageProduct
    {
        if ($product->isRental()) {
            throw new \RuntimeException('Rental listings are synced via Services pull, not product pull.');
        }

        if (! $product->ghl_product_id) {
            throw new \RuntimeException('Product has no GHL ID. Push to GHL first.');
        }

        $locationId = $this->client->getLocationId();
        $query = ['locationId' => $locationId];

        $raw = $this->client->get("products/{$product->ghl_product_id}", $query);
        $productData = $raw['product'] ?? $raw;

        $product->update([
            'name' => $productData['name'] ?? $product->name,
            'description' => $productData['description'] ?? $product->description,
            'available_in_store' => $productData['availableInStore'] ?? $product->available_in_store,
            'is_taxes_enabled' => $productData['isTaxesEnabled'] ?? $product->is_taxes_enabled,
            'tax_inclusive' => $productData['taxInclusive'] ?? $product->tax_inclusive,
            'track_product_inventory' => $productData['trackProductInventory'] ?? $product->track_product_inventory,
            'status' => $productData['status'] ?? $product->status,
            'slug' => $productData['slug'] ?? $product->slug,
            'engage_sync_status' => 'synced',
            'engage_last_synced_at' => now(),
        ]);

        $this->syncCategoriesFromGhl($product, $productData);

        $this->pullDefaultPriceFromGhl($product, $query);

        return $product->fresh()->load(['categories']);
    }

    /**
     * Maps GHL's `collectionIds` (the same field name syncProductToGhl()
     * sends when pushing, confirmed against GHL's Products OpenAPI spec —
     * a plain array of collection id strings) back onto the local
     * product_categories pivot via each Category's engage_collection_id.
     * A GHL collection id with no matching local Category is skipped (that
     * category hasn't been pulled locally yet — see pullCategoriesFromGhl()).
     * If the key is missing entirely (some GHL payloads omit it) we leave
     * existing associations untouched rather than wiping them; an explicit
     * empty array is treated as "GHL says no categories" and clears the pivot.
     */
    private function syncCategoriesFromGhl(EngageProduct $product, array $ghlProduct): void
    {
        $collectionIds = $ghlProduct['collectionIds'] ?? null;

        if (! is_array($collectionIds)) {
            return;
        }

        $ghlIds = collect($collectionIds)
            ->map(fn ($c) => is_array($c) ? ($c['id'] ?? $c['_id'] ?? null) : $c)
            ->filter()
            ->values();

        $localCategoryIds = EngageCategory::where('engage_organization_location_id', $product->engage_organization_location_id)
            ->whereIn('engage_collection_id', $ghlIds)
            ->pluck('id');

        $product->categories()->sync($localCategoryIds);
    }

    public function deleteProductFromGhl(EngageProduct $product): void
    {
        if (! $product->ghl_product_id) {
            return;
        }

        try {
            $this->client->delete("products/{$product->ghl_product_id}");

            $product->update([
                'ghl_product_id' => null,
                'engage_sync_status' => 'not_synced',
            ]);
        } catch (\Exception $e) {
            Log::error('GHL product delete failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function syncCategoryToGhl(EngageCategory $category): EngageCategory
    {
        $category->update(['engage_sync_status' => 'pending']);

        $locationId = $this->client->getLocationId();

        $payload = [
            'altId' => $locationId,
            'altType' => 'location',
            'name' => $category->name,
            'slug' => $category->slug,
        ];

        if ($category->image) {
            $payload['image'] = $category->image;
        }

        try {
            $response = $category->engage_collection_id
                ? $this->client->put("products/collections/{$category->engage_collection_id}", $payload)
                : $this->client->post('products/collections/', $payload);

            Log::info('GHL collection sync response', ['response' => $response]);

            $ghlId = $this->extractId($response, $category->engage_collection_id);

            $category->update([
                'engage_collection_id' => $ghlId,
                'engage_sync_status' => 'synced',
                'engage_last_synced_at' => now(),
            ]);

            return $category->fresh();
        } catch (\Exception $e) {
            Log::error('GHL category sync failed', [
                'category_id' => $category->id,
                'error' => $e->getMessage(),
            ]);

            $category->update(['engage_sync_status' => 'error']);

            throw $e;
        }
    }

    public function deleteCategoryFromGhl(EngageCategory $category): void
    {
        if (! $category->engage_collection_id) {
            return;
        }

        try {
            $this->client->delete("products/collections/{$category->engage_collection_id}");

            $category->update([
                'engage_collection_id' => null,
                'engage_sync_status' => 'not_synced',
            ]);
        } catch (\Exception $e) {
            Log::error('GHL category delete failed', [
                'category_id' => $category->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function bulkSyncProducts(string $locationId): array
    {
        $results = ['synced' => 0, 'errors' => 0, 'error_details' => []];

        $products = EngageProduct::byLocation($locationId)
            ->whereNull('product_rental_id')
            ->where('status', 'active')
            ->get();

        foreach ($products as $product) {
            try {
                $this->syncProductToGhl($product);
                $results['synced']++;
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_details'][] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function bulkPullFromGhl(string $locationId): array
    {
        $results = ['pulled' => 0, 'created' => 0, 'errors' => 0, 'deactivated' => 0, 'error_details' => []];

        $rentalProductIds = array_flip($this->serviceSync->fetchRentalProductIds());

        try {
            $seenGhlIds = [];

            foreach ($this->fetchAllGhlProducts() as $ghlProduct) {
                $ghlId = $ghlProduct['_id'] ?? $ghlProduct['id'] ?? null;

                if ($ghlId !== null) {
                    $seenGhlIds[] = $ghlId;
                }

                if ($ghlId !== null && isset($rentalProductIds[$ghlId])) {
                    continue;
                }

                if ($this->createLocalStubIfMissing($ghlProduct, $locationId)) {
                    $results['created']++;
                }
            }

            $results['deactivated'] = $this->deactivateMissingProducts($locationId, $seenGhlIds);
        } catch (\Exception $e) {
            $results['errors']++;
            $results['error_details'][] = ['error' => 'GHL product list fetch failed: '.$e->getMessage()];
            Log::error('GHL product list fetch failed', ['error' => $e->getMessage()]);
        }

        $products = EngageProduct::byLocation($locationId)
            ->whereNull('product_rental_id')
            ->whereNotNull('ghl_product_id')
            ->get();

        foreach ($products as $product) {
            try {
                $this->pullFromGhl($product);
                $results['pulled']++;
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_details'][] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Delete-sync: a regular-catalog Product previously pulled/synced from
     * GHL (ghl_product_id set) that no longer appears in GHL's current
     * product list is soft-deleted, not hard-deleted — Product already uses
     * SoftDeletes, so this is fully reversible and safe against any
     * ProductTransactionItem/category-pivot/cart reference still pointing
     * at the row (a soft delete never removes the row or breaks a foreign
     * key, it only hides it from default queries). Reappearance is handled
     * by createLocalStubIfMissing()'s own restore step above.
     *
     * Scoped to `whereNull('product_rental_id')` — rental listings are
     * handled separately by GhlServiceSyncService's own delete-sync
     * (archiveMissingRentalListings()), never here.
     *
     * Guarded on a non-empty $seenGhlIds — see
     * deactivateMissingCategories()'s doc comment above for why an
     * empty/failed fetch must never be trusted as "GHL has zero products
     * now."
     */
    private function deactivateMissingProducts(string $locationId, array $seenGhlIds): int
    {
        if (empty($seenGhlIds)) {
            return 0;
        }

        $stale = EngageProduct::byLocation($locationId)
            ->whereNull('product_rental_id')
            ->whereNotNull('ghl_product_id')
            ->whereNotIn('ghl_product_id', $seenGhlIds)
            ->get();

        foreach ($stale as $product) {
            $product->delete();
        }

        return $stale->count();
    }

    public function bulkSyncCategories(string $locationId): array
    {
        $results = ['synced' => 0, 'errors' => 0, 'error_details' => []];

        $categories = EngageCategory::where('engage_organization_location_id', $locationId)
            ->where('is_active', true)
            ->get();

        foreach ($categories as $category) {
            try {
                $this->syncCategoryToGhl($category);
                $results['synced']++;
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_details'][] = [
                    'category_id' => $category->id,
                    'name' => $category->name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Pull GHL "Collections" (Categories) into the local catalog — the
     * category-typed sibling of bulkPullFromGhl() for products. Existing
     * categories (matched by engage_collection_id) are updated in place;
     * everything else is created fresh, same "create local stub if missing,
     * update if not" pattern.
     */
    public function pullCategoriesFromGhl(string $locationId): array
    {
        $results = ['pulled' => 0, 'created' => 0, 'errors' => 0, 'deactivated' => 0, 'error_details' => []];

        try {
            $seenGhlIds = [];

            foreach ($this->fetchAllGhlCollections() as $ghlCollection) {
                $ghlId = $ghlCollection['_id'] ?? $ghlCollection['id'] ?? null;

                if (! $ghlId) {
                    continue;
                }

                $seenGhlIds[] = $ghlId;

                $data = [
                    'name' => $ghlCollection['name'] ?? 'Untitled',
                    'slug' => $ghlCollection['slug'] ?? null,
                    'image' => empty($ghlCollection['image']) ? null : $ghlCollection['image'],
                    'engage_collection_id' => $ghlId,
                    // GHL still has this collection right now — reactivate it
                    // if a previous pull's delete-sync (below) had deactivated
                    // it. Category has no `isActive` concept of its own on the
                    // GHL side; "is in this pull's list" IS the signal.
                    'is_active' => true,
                    'engage_sync_status' => 'synced',
                    'engage_last_synced_at' => now(),
                    'engage_organization_location_id' => $locationId,
                ];

                $category = EngageCategory::where('engage_organization_location_id', $locationId)
                    ->where('engage_collection_id', $ghlId)
                    ->first();

                if ($category) {
                    $category->update($data);
                } else {
                    EngageCategory::create($data + ['sort_order' => 0]);
                    $results['created']++;
                }

                $results['pulled']++;
            }

            $results['deactivated'] = $this->deactivateMissingCategories($locationId, $seenGhlIds);
        } catch (\Exception $e) {
            $results['errors']++;
            $results['error_details'][] = ['error' => 'GHL collection list fetch failed: '.$e->getMessage()];
            Log::error('GHL collection list fetch failed', ['error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Delete-sync: a Category previously pulled from GHL (engage_collection_id
     * set) that no longer appears in GHL's current collection list is
     * deactivated (is_active=false), never hard-deleted — Category has no
     * SoftDeletes trait, and a hard DELETE here could break the
     * product_categories pivot or a Products-page category filter mid-use.
     * Deactivation is fully reversible (the very next pull reactivates it —
     * see the `'is_active' => true` write above — the moment GHL has it
     * again) and immediately removes it from every active-only
     * filter/listing surface, which is what "no longer exists" means in
     * practice for a taxonomy row with no bookings/transactions of its own.
     *
     * Only ever touches rows that are themselves GHL-sourced
     * (engage_collection_id IS NOT NULL) — a locally-created category with
     * no GHL id is never touched, since it was never something GHL could
     * "still have" or "no longer have" to begin with.
     *
     * Guarded on a non-empty $seenGhlIds — this method is only ever reached
     * once the fetch loop above has fully completed without throwing (an
     * exception jumps straight to the catch block, this line is never
     * reached), but a *successful-yet-suspiciously-empty* response is a
     * real, distinct risk this codebase has already hit once this session
     * (a different GHL endpoint returning an empty list under a shape bug).
     * Trusting "0 items" as "GHL now has zero categories" would deactivate
     * every local category from the cheapest possible failure mode, so it's
     * deliberately not trusted — a genuine "GHL emptied its whole category
     * list" case is left for staff to action manually, or resolves itself
     * automatically the moment a real, non-empty pull happens again.
     */
    private function deactivateMissingCategories(string $locationId, array $seenGhlIds): int
    {
        if (empty($seenGhlIds)) {
            return 0;
        }

        return EngageCategory::where('engage_organization_location_id', $locationId)
            ->whereNotNull('engage_collection_id')
            ->whereNotIn('engage_collection_id', $seenGhlIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    private function fetchAllGhlCollections(): array
    {
        $locationId = $this->client->getLocationId();
        $all = [];
        $offset = 0;
        $limit = 100;

        do {
            $response = $this->client->get('products/collections', [
                'altId' => $locationId,
                'altType' => 'location',
                'limit' => $limit,
                'offset' => $offset,
            ]);

            $batch = $response['data'] ?? [];
            $all = array_merge($all, $batch);
            $offset += $limit;
        } while (count($batch) === $limit);

        return $all;
    }

    private function syncDefaultPriceToGhl(EngageProduct $product): void
    {
        if (! $product->ghl_product_id || $product->price === null) {
            return;
        }

        $locationId = $this->client->getLocationId();
        $query = ['locationId' => $locationId];
        $endpoint = "products/{$product->ghl_product_id}/price/";

        $payload = [
            'altId' => $locationId,
            'altType' => 'location',
            'locationId' => $locationId,
            'name' => 'Default',
            'type' => 'one_time',
            'currency' => 'USD',
            'amount' => (float) $product->price,
        ];

        try {
            $pricesRaw = $this->client->get($endpoint, $query);
            $ghlPrices = $pricesRaw['prices'] ?? $pricesRaw['data'] ?? [];
            $defaultPrice = collect($ghlPrices)->first(fn ($p) => empty($p['variantOptionIds'] ?? []));
            $defaultPriceId = $defaultPrice['_id'] ?? $defaultPrice['id'] ?? null;

            if ($defaultPriceId) {
                $this->client->put("{$endpoint}{$defaultPriceId}", $payload);
            } else {
                $this->client->post($endpoint, $payload);
            }
        } catch (\Exception $e) {
            Log::warning('GHL default price sync failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function pullDefaultPriceFromGhl(EngageProduct $product, array $query): void
    {
        try {
            $pricesRaw = $this->client->get("products/{$product->ghl_product_id}/price/", $query);
            $ghlPrices = $pricesRaw['prices'] ?? $pricesRaw['data'] ?? [];

            $defaultPrice = collect($ghlPrices)->first(fn ($p) => empty($p['variantOptionIds'] ?? []));

            if ($defaultPrice) {
                $product->update([
                    'price' => (float) ($defaultPrice['amount'] ?? $product->price ?? 0),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('GHL default price pull failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fetchAllGhlProducts(): array
    {
        $locationId = $this->client->getLocationId();
        $all = [];
        $offset = 0;
        $limit = 100;

        do {
            $response = $this->client->get('products/', [
                'locationId' => $locationId,
                'limit' => $limit,
                'offset' => $offset,
            ]);

            $batch = $response['products'] ?? $response['data'] ?? [];
            $all = array_merge($all, $batch);
            $offset += $limit;
        } while (count($batch) === $limit);

        return $all;
    }

    private function createLocalStubIfMissing(array $ghlProduct, string $locationId): bool
    {
        $ghlId = $ghlProduct['_id'] ?? $ghlProduct['id'] ?? null;

        if (! $ghlId) {
            return false;
        }

        // A row soft-deleted by a prior delete-sync run
        // (deactivateMissingProducts() below) whose product has reappeared
        // in this GHL pull needs to be restored, not left permanently
        // hidden — without this, updateOrCreate-style logic elsewhere would
        // find no *visible* match (soft-deleted rows are excluded from
        // default queries) and create a genuine duplicate row for the same
        // ghl_product_id, since that column has no unique constraint.
        // Restoring here is what lets bulkPullFromGhl()'s second loop
        // (EngageProduct::byLocation()->...->get(), which only sees non-trashed
        // rows) pick this product back up and refresh its data moments
        // later in the same pull run.
        $trashedMatch = EngageProduct::onlyTrashed()->byLocation($locationId)->where('ghl_product_id', $ghlId)->first();
        if ($trashedMatch) {
            $trashedMatch->restore();

            return false;
        }

        $exists = EngageProduct::byLocation($locationId)
            ->where('ghl_product_id', $ghlId)
            ->exists();

        if ($exists) {
            return false;
        }

        $type = strtoupper($ghlProduct['productType'] ?? '');
        $name = $ghlProduct['name'] ?? 'Untitled';

        // This method only ever runs for non-rental catalog goods (rentals
        // are filtered out by the caller via fetchRentalProductIds()), so a
        // SKU/barcode is always appropriate here — same auto-generation
        // ProductService::create() applies to a manually-added product,
        // kept in sync so a GHL-pulled product is never left without one.
        $product = EngageProduct::create([
            'name' => $name,
            'product_type' => in_array($type, ['PHYSICAL', 'DIGITAL', 'SERVICE']) ? $type : 'PHYSICAL',
            'description' => $ghlProduct['description'] ?? null,
            'status' => 'active',
            // GHL's regular Products API (GET /products/) only ever
            // returns a single `image` field, not an array — wrapped into
            // one position:0 entry, the same shape rental services store
            // multiple images in. `image` itself is a computed accessor
            // derived from images[0], see EngageProduct::image().
            'images' => empty($ghlProduct['image']) ? [] : [[
                '_id' => null,
                'url' => $ghlProduct['image'],
                'name' => $name,
                'position' => 0,
            ]],
            'available_in_store' => $ghlProduct['availableInStore'] ?? true,
            'ghl_product_id' => $ghlId,
            'engage_sync_status' => 'pending',
            'engage_organization_location_id' => $locationId,
            'sku' => $this->productService->generateUniqueSku($locationId, $name),
        ]);

        $this->syncCategoriesFromGhl($product, $ghlProduct);

        return true;
    }

    private function uploadImageToGhl(EngageProduct $product): ?string
    {
        if (! $product->image) {
            return null;
        }

        if ($product->ghl_image_url && str_contains($product->ghl_image_url, 'cdn.filesafe.space')) {
            return $product->ghl_image_url;
        }

        if (str_contains((string) $product->image, 'cdn.filesafe.space')) {
            $product->update(['ghl_image_url' => $product->image]);

            return $product->image;
        }

        $rawImage = $product->image;

        if (str_starts_with($rawImage, '/storage/')) {
            $storageDisk = Storage::disk('public');
            $relativePath = ltrim(substr($rawImage, strlen('/storage')), '/');
            $localPath = $storageDisk->path($relativePath);

            if (file_exists($localPath)) {
                try {
                    $filename = basename($localPath);
                    $mimeType = mime_content_type($localPath) ?: 'image/jpeg';

                    $uploadResponse = $this->client->uploadFile($localPath, $filename, $mimeType);

                    $cdnUrl = null;
                    if (! empty($uploadResponse['uploadedFiles']) && is_array($uploadResponse['uploadedFiles'])) {
                        $cdnUrl = array_values($uploadResponse['uploadedFiles'])[0] ?? null;
                    }
                    $cdnUrl ??= $uploadResponse['url'] ?? $uploadResponse['fileUrl'] ?? null;

                    if ($cdnUrl) {
                        $product->update(['ghl_image_url' => $cdnUrl]);

                        return $cdnUrl;
                    }
                } catch (\Exception $e) {
                    Log::warning('GHL image upload failed — using fallback', [
                        'product_id' => $product->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return null;
        }

        if (str_starts_with($rawImage, 'http')) {
            return $rawImage;
        }

        return null;
    }

    private function extractId(array $response, ?string $fallback = null): ?string
    {
        return $response['_id']
            ?? $response['id']
            ?? $response['product']['_id'] ?? $response['product']['id']
            ?? $response['data']['_id'] ?? $response['data']['id']
            ?? $response['collection']['_id'] ?? $response['collection']['id']
            ?? $fallback;
    }
}
