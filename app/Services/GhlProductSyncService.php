<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GhlProductSyncService
{
    public function __construct(
        private GhlClient $client,
    ) {}

    // ── Product ───────────────────────────────────────────────────────────────

    public function syncProductToGhl(Product $product): Product
    {
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
            'isTaxesEnabled' => $product->is_taxes_enabled ?? false,
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

        if ($product->medias && is_array($product->medias)) {
            $payload['medias'] = array_map(function ($media) {
                if (isset($media['url']) && ! str_starts_with($media['url'], 'http')) {
                    $media['url'] = config('app.url').$media['url'];
                }

                return $media;
            }, $product->medias);
        }

        // Category → GHL collection IDs
        $categoryIds = $product->categories()->pluck('engage_collection_id')->filter()->values()->toArray();
        if (! empty($categoryIds)) {
            $payload['collectionIds'] = $categoryIds;
        }

        // Variant groups → GHL variants payload
        $product->loadMissing('variants.options');
        $variantsPayload = $this->buildVariantsPayload($product);
        if (! empty($variantsPayload)) {
            $payload['variants'] = $variantsPayload;
            $payload['isVariable'] = true;
        }

        try {
            $isNew = ! $product->engage_product_id;

            $response = $isNew
                ? $this->client->post('products/', $payload)
                : $this->client->put("products/{$product->engage_product_id}", $payload);

            Log::info('GHL product sync response', ['product' => $product->name, 'response' => $response]);

            $ghlId = $this->extractId($response, $product->engage_product_id);

            $product->update([
                'engage_product_id' => $ghlId,
                'engage_sync_status' => 'synced',
                'engage_last_synced_at' => now(),
            ]);

            // New GHL product: clear all stale local GHL IDs so they're re-created fresh
            if ($isNew) {
                $product->prices()->update([
                    'engage_price_id' => null,
                    'engage_sync_status' => 'not_synced',
                ]);
                $product->variants()->update(['ghl_variant_id' => null]);
                $product->variantOptions()->update(['ghl_option_id' => null]);
            }

            // Store GHL-assigned variant/option IDs returned in the response
            if (! empty($response['variants'])) {
                $this->storeGhlOptionIds($product, $response['variants']);
            }

            // Sync prices via the prices API (separate endpoint)
            $this->syncPricesForProduct($product->fresh()->load('variants.options', 'variantOptions'));

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

    // ── Pull from GHL ─────────────────────────────────────────────────────────

    public function pullFromGhl(Product $product): Product
    {
        if (! $product->engage_product_id) {
            throw new \RuntimeException('Product has no GHL ID. Push to GHL first.');
        }

        $locationId = $this->client->getLocationId();
        $query = ['locationId' => $locationId];

        $raw = $this->client->get("products/{$product->engage_product_id}", $query);
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

        Log::info('GHL pull product data', ['product' => $product->name, 'ghl_data' => $productData]);

        $this->pullVariantsFromGhl($product, $productData['variants'] ?? []);

        $pricesRaw = $this->client->get("products/{$product->engage_product_id}/price/", $query);
        $ghlPrices = $pricesRaw['prices'] ?? $pricesRaw['data'] ?? [];

        Log::info('GHL pull prices data', ['product' => $product->name, 'prices' => $ghlPrices]);

        $this->pullPricesFromGhl($product, $ghlPrices);

        return $product->fresh()->load(['categories', 'prices', 'variants.options', 'amenities', 'features']);
    }

    private function pullVariantsFromGhl(Product $product, array $ghlVariants): void
    {
        $seenVariantIds = [];
        $seenOptionIds = [];

        foreach ($ghlVariants as $position => $ghlVariant) {
            $variantName = $ghlVariant['name'] ?? null;
            $ghlVariantId = $ghlVariant['id'] ?? null;
            if (! $variantName) {
                continue;
            }

            $localVariant = ($ghlVariantId ? $product->variants()->where('ghl_variant_id', $ghlVariantId)->first() : null)
                ?? $product->variants()->where('name', $variantName)->first();

            if ($localVariant) {
                $localVariant->update([
                    'name' => $variantName,
                    'ghl_variant_id' => $ghlVariantId ?? $localVariant->ghl_variant_id,
                    'position' => $position,
                ]);
            } else {
                $localVariant = $product->variants()->create([
                    'product_id' => $product->id,
                    'name' => $variantName,
                    'ghl_variant_id' => $ghlVariantId,
                    'position' => $position,
                ]);
            }

            $seenVariantIds[] = $localVariant->id;

            foreach ($ghlVariant['options'] ?? [] as $optPos => $ghlOption) {
                $ghlOptionId = $ghlOption['id'] ?? null;
                $optionName = $ghlOption['name'] ?? null;

                if (! $ghlOptionId || ! $optionName) {
                    continue;
                }

                $localOption = $localVariant->options()->where('ghl_option_id', $ghlOptionId)->first()
                    ?? $localVariant->options()->where('name', $optionName)->first();

                if ($localOption) {
                    $localOption->update([
                        'name' => $optionName,
                        'ghl_option_id' => $ghlOptionId,
                        'position' => $optPos,
                    ]);
                } else {
                    $localOption = $localVariant->options()->create([
                        'product_id' => $product->id,
                        'name' => $optionName,
                        'ghl_option_id' => $ghlOptionId,
                        'position' => $optPos,
                    ]);
                }

                $seenOptionIds[] = $localOption->id;
            }
        }

        if (! empty($seenOptionIds)) {
            $product->variantOptions()
                ->whereNotNull('ghl_option_id')
                ->whereNotIn('id', $seenOptionIds)
                ->delete();
        } elseif (empty($ghlVariants)) {
            $product->variantOptions()->whereNotNull('ghl_option_id')->delete();
        }

        if (! empty($seenVariantIds)) {
            $product->variants()->whereNotIn('id', $seenVariantIds)->delete();
        } elseif (empty($ghlVariants)) {
            $product->variants()->delete();
        }
    }

    /**
     * Pull GHL prices into product_prices.
     * Every price — whether it covers one option or many — goes to product_prices.
     * variantOptionIds from GHL are mapped to local ProductVariantOption ULIDs
     * and stored as variant_option_ids (JSON) on the price row.
     */
    private function pullPricesFromGhl(Product $product, array $ghlPrices): void
    {
        $seenLocalPriceIds = [];
        $product->loadMissing('variantOptions');

        foreach ($ghlPrices as $ghlPrice) {
            $ghlPriceId = $ghlPrice['_id'] ?? $ghlPrice['id'] ?? null;
            if (! $ghlPriceId) {
                continue;
            }

            $ghlOptionIds = $ghlPrice['variantOptionIds'] ?? [];

            // Translate GHL option IDs → local ProductVariantOption ULIDs
            $localOptionIds = collect($ghlOptionIds)
                ->map(fn ($ghlOptId) => $product->variantOptions->firstWhere('ghl_option_id', $ghlOptId)?->id)
                ->filter()
                ->values()
                ->toArray();

            $priceData = [
                'name' => $ghlPrice['name'] ?? 'Default',
                'type' => $ghlPrice['type'] ?? 'one_time',
                'amount' => (float) ($ghlPrice['amount'] ?? 0),
                'compare_at_price' => isset($ghlPrice['compareAtPrice']) ? (float) $ghlPrice['compareAtPrice'] : null,
                'currency' => $ghlPrice['currency'] ?? 'USD',
                'variant_option_ids' => ! empty($localOptionIds) ? $localOptionIds : null,
                'track_inventory' => $ghlPrice['trackInventory'] ?? false,
                'available_quantity' => isset($ghlPrice['availableQuantity']) && $ghlPrice['availableQuantity'] > 0
                    ? (int) $ghlPrice['availableQuantity']
                    : null,
                'recurring_interval' => $ghlPrice['recurring']['interval'] ?? null,
                'recurring_interval_count' => $ghlPrice['recurring']['intervalCount'] ?? null,
                'engage_price_id' => $ghlPriceId,
                'engage_sync_status' => 'synced',
                'deleted' => false,
            ];

            // Match: engage_price_id → variant combo → first unlinked default price
            $localPrice = $product->prices()->where('engage_price_id', $ghlPriceId)->first();

            if (! $localPrice && ! empty($localOptionIds)) {
                $sorted = collect($localOptionIds)->sort()->values()->toArray();
                $localPrice = $product->prices()
                    ->whereNotNull('variant_option_ids')
                    ->get()
                    ->first(function ($p) use ($sorted) {
                        return collect($p->variant_option_ids)->sort()->values()->toArray() === $sorted;
                    });
            }

            if (! $localPrice && empty($localOptionIds)) {
                $localPrice = $product->prices()
                    ->whereNull('engage_price_id')
                    ->where('deleted', false)
                    ->whereNull('variant_option_ids')
                    ->first();
            }

            if ($localPrice) {
                $localPrice->update($priceData);
            } else {
                $localPrice = $product->prices()->create(
                    array_merge($priceData, ['product_id' => $product->id])
                );
            }

            $seenLocalPriceIds[] = $localPrice->id;
        }

        // Soft-delete local synced prices no longer in GHL
        if (! empty($seenLocalPriceIds)) {
            $product->prices()
                ->whereNotNull('engage_price_id')
                ->whereNotIn('id', $seenLocalPriceIds)
                ->update(['deleted' => true, 'engage_sync_status' => 'not_synced']);
        }
    }

    public function deleteProductFromGhl(Product $product): void
    {
        if (! $product->engage_product_id) {
            return;
        }

        try {
            $this->client->delete("products/{$product->engage_product_id}");

            $product->update([
                'engage_product_id' => null,
                'engage_sync_status' => 'not_synced',
            ]);
        } catch (\Exception $e) {
            Log::error('GHL product delete failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Prices ────────────────────────────────────────────────────────────────

    /**
     * Sync a single ProductPrice to GHL.
     * If the price has variant_option_ids, those local option ULIDs are translated
     * to GHL option IDs and sent as variantOptionIds in the payload.
     * Find-or-create by combo key prevents duplicates on re-sync.
     */
    public function syncPriceToGhl(ProductPrice $price, array $ghlPriceMap = []): ProductPrice
    {
        $product = $price->product;

        if (! $product->engage_product_id) {
            throw new \RuntimeException('Product must be synced to GHL before syncing prices.');
        }

        $price->update(['engage_sync_status' => 'pending']);

        $locationId = $this->client->getLocationId();

        $payload = [
            'altId' => $locationId,
            'altType' => 'location',
            'locationId' => $locationId,
            'name' => $price->name,
            'type' => $price->type,
            'currency' => $price->currency ?? 'USD',
            'amount' => (float) $price->amount,
        ];

        if ($price->compare_at_price !== null) {
            $payload['compareAtPrice'] = (float) $price->compare_at_price;
        }

        if ($price->type === 'recurring' && $price->recurring_interval) {
            $payload['recurring'] = [
                'interval' => $price->recurring_interval,
                'intervalCount' => $price->recurring_interval_count ?? 1,
            ];
        }

        if ($price->track_inventory) {
            $payload['trackInventory'] = true;
            $payload['availableQuantity'] = $price->available_quantity ?? 0;
        }

        // Build variantOptionIds: map local ULIDs → GHL option IDs
        $ghlOptionIds = [];
        if (! empty($price->variant_option_ids)) {
            $product->loadMissing('variantOptions');
            $ghlOptionIds = collect($price->variant_option_ids)
                ->map(fn ($localId) => $product->variantOptions->firstWhere('id', $localId)?->ghl_option_id)
                ->filter()
                ->values()
                ->toArray();

            if (! empty($ghlOptionIds)) {
                $payload['variantOptionIds'] = $ghlOptionIds;
            }
        }

        try {
            $endpoint = "products/{$product->engage_product_id}/price/";
            $comboKey = ! empty($ghlOptionIds) ? $this->makeComboKey($ghlOptionIds) : null;

            if (! $price->engage_price_id && $comboKey && isset($ghlPriceMap[$comboKey])) {
                // GHL already has this combination price but we didn't have its ID — update it
                $existingId = $ghlPriceMap[$comboKey];
                $response = $this->client->put("{$endpoint}{$existingId}", $payload);
                $ghlPriceId = $this->extractId($response, $existingId);
            } elseif ($price->engage_price_id) {
                $response = $this->client->put("{$endpoint}{$price->engage_price_id}", $payload);
                $ghlPriceId = $this->extractId($response, $price->engage_price_id);
            } else {
                $response = $this->client->post($endpoint, $payload);
                $ghlPriceId = $this->extractId($response);
            }

            Log::info('GHL price sync response', ['price' => $price->name, 'response' => $response]);

            $price->update([
                'engage_price_id' => $ghlPriceId,
                'engage_sync_status' => 'synced',
                'sync_error_message' => null,
            ]);

            return $price->fresh();
        } catch (\Exception $e) {
            Log::error('GHL price sync failed', [
                'price_id' => $price->id,
                'error' => $e->getMessage(),
            ]);

            $price->update([
                'engage_sync_status' => 'error',
                'sync_error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function deletePriceFromGhl(ProductPrice $price): void
    {
        if (! $price->engage_price_id || ! $price->product?->engage_product_id) {
            return;
        }

        try {
            $this->client->delete(
                "products/{$price->product->engage_product_id}/price/{$price->engage_price_id}"
            );

            $price->update([
                'engage_price_id' => null,
                'engage_sync_status' => 'not_synced',
            ]);
        } catch (\Exception $e) {
            Log::error('GHL price delete failed', [
                'price_id' => $price->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Categories ────────────────────────────────────────────────────────────

    public function syncCategoryToGhl(Category $category): Category
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

    public function deleteCategoryFromGhl(Category $category): void
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

    // ── Bulk helpers ──────────────────────────────────────────────────────────

    /**
     * Sync all product_prices for a product.
     * Fetches existing GHL prices once upfront to prevent duplicate creates
     * when re-syncing the same combination prices.
     */
    public function syncPricesForProduct(Product $product): void
    {
        if (! $product->engage_product_id) {
            return;
        }

        $ghlPriceMap = $this->fetchGhlPricesByComboKey($product->engage_product_id);
        $product->loadMissing('variantOptions');

        foreach ($product->prices()->where('deleted', false)->get() as $price) {
            try {
                $this->syncPriceToGhl($price, $ghlPriceMap);
            } catch (\Exception) {
                continue;
            }
        }
    }

    public function bulkSyncProducts(string $tenantId): array
    {
        $results = ['synced' => 0, 'errors' => 0, 'error_details' => []];

        $products = Product::byTenant($tenantId)
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

    public function bulkPullFromGhl(string $tenantId): array
    {
        $results = ['pulled' => 0, 'created' => 0, 'errors' => 0, 'error_details' => []];

        // Discover the full GHL catalog first, so brand-new GHL products get a
        // local row too — not just ones we already know about.
        try {
            foreach ($this->fetchAllGhlProducts() as $ghlProduct) {
                if ($this->createLocalStubIfMissing($ghlProduct, $tenantId)) {
                    $results['created']++;
                }
            }
        } catch (\Exception $e) {
            $results['errors']++;
            $results['error_details'][] = ['error' => 'GHL product list fetch failed: '.$e->getMessage()];
            Log::error('GHL product list fetch failed', ['error' => $e->getMessage()]);
        }

        $products = Product::byTenant($tenantId)
            ->whereNotNull('engage_product_id')
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

    /** Fetch every product from GHL's catalog, paging until exhausted. */
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

    /**
     * Create a local row for a GHL product we don't know yet; the follow-up
     * pullFromGhl pass fills in variants and prices. Returns true if created.
     */
    private function createLocalStubIfMissing(array $ghlProduct, string $tenantId): bool
    {
        $ghlId = $ghlProduct['_id'] ?? $ghlProduct['id'] ?? null;

        if (! $ghlId) {
            return false;
        }

        // withTrashed: don't resurrect products deleted locally on purpose
        $exists = Product::withTrashed()
            ->byTenant($tenantId)
            ->where('engage_product_id', $ghlId)
            ->exists();

        if ($exists) {
            return false;
        }

        $type = strtoupper($ghlProduct['productType'] ?? '');

        Product::create([
            'name' => $ghlProduct['name'] ?? 'Untitled',
            'product_type' => in_array($type, ['PHYSICAL', 'DIGITAL', 'SERVICE']) ? $type : 'PHYSICAL',
            'description' => $ghlProduct['description'] ?? null,
            'status' => 'active',
            'image' => $ghlProduct['image'] ?? null,
            'available_in_store' => $ghlProduct['availableInStore'] ?? true,
            'engage_product_id' => $ghlId,
            'engage_sync_status' => 'pending',
            'tenant_id' => $tenantId,
        ]);

        return true;
    }

    public function bulkSyncCategories(string $tenantId): array
    {
        $results = ['synced' => 0, 'errors' => 0, 'error_details' => []];

        $categories = Category::where('tenant_id', $tenantId)
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

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build GHL variants payload from local variant groups.
     * Sends GHL-assigned group/option IDs when available; local ULIDs as
     * placeholders for the initial create only.
     */
    private function buildVariantsPayload(Product $product): array
    {
        $payload = [];

        foreach ($product->variants as $variant) {
            if ($variant->options->isEmpty()) {
                continue;
            }

            $options = $variant->options->map(fn ($opt) => [
                'id' => $opt->ghl_option_id ?? $opt->id,
                'name' => $opt->name,
            ])->values()->toArray();

            $payload[] = [
                'id' => $variant->ghl_variant_id ?? $variant->id,
                'name' => $variant->name,
                'options' => $options,
            ];
        }

        return $payload;
    }

    /**
     * After product create/update GHL returns the variant structure with its own IDs.
     * Match back to local variants/options by name and persist the GHL-assigned IDs.
     */
    private function storeGhlOptionIds(Product $product, array $ghlVariants): void
    {
        $product->loadMissing('variants.options');

        foreach ($ghlVariants as $ghlVariant) {
            $variantName = $ghlVariant['name'] ?? null;
            $ghlVariantId = $ghlVariant['id'] ?? null;
            $localVariant = $product->variants->firstWhere('name', $variantName);

            if (! $localVariant) {
                continue;
            }

            if ($ghlVariantId && $localVariant->ghl_variant_id !== $ghlVariantId) {
                $localVariant->update(['ghl_variant_id' => $ghlVariantId]);
            }

            foreach ($ghlVariant['options'] ?? [] as $ghlOption) {
                $optionName = $ghlOption['name'] ?? null;
                $ghlOptionId = $ghlOption['id'] ?? null;

                if (! $optionName || ! $ghlOptionId) {
                    continue;
                }

                $localOption = $localVariant->options->firstWhere('name', $optionName);

                if ($localOption && $localOption->ghl_option_id !== $ghlOptionId) {
                    $localOption->update(['ghl_option_id' => $ghlOptionId]);
                }
            }
        }
    }

    /**
     * Fetch all GHL prices for a product and return a map of
     * sorted-combo-key → ghl_price_id.
     * Used to detect existing combination prices before creating new ones.
     */
    private function fetchGhlPricesByComboKey(string $ghlProductId): array
    {
        try {
            $locationId = $this->client->getLocationId();
            $response = $this->client->get("products/{$ghlProductId}/price/", [
                'locationId' => $locationId,
            ]);
            $prices = $response['prices'] ?? $response['data'] ?? [];

            $map = [];
            foreach ($prices as $price) {
                $priceId = $price['_id'] ?? $price['id'] ?? null;
                $optionIds = $price['variantOptionIds'] ?? [];
                if ($priceId && ! empty($optionIds)) {
                    $map[$this->makeComboKey($optionIds)] = $priceId;
                }
            }

            return $map;
        } catch (\Exception $e) {
            Log::warning('GHL fetch prices failed — skipping find-or-create dedup', [
                'product_id' => $ghlProductId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function makeComboKey(array $optionIds): string
    {
        sort($optionIds);

        return implode(',', $optionIds);
    }

    /**
     * Upload the product's image to GHL's media library (if not already uploaded)
     * and return the CDN URL.  On failure, falls back to the full public URL when
     * the image is already publicly accessible; returns null for local-only paths.
     *
     * Caching: ghl_image_url is set after a successful upload and cleared by
     * ProductService::uploadImage() whenever the user replaces the local image.
     */
    private function uploadImageToGhl(Product $product): ?string
    {
        if (! $product->image) {
            return null;
        }

        // Already cached — skip re-upload
        if ($product->ghl_image_url && str_contains($product->ghl_image_url, 'cdn.filesafe.space')) {
            return $product->ghl_image_url;
        }

        // Image is already a GHL CDN URL — cache it and return
        if (str_contains((string) $product->image, 'cdn.filesafe.space')) {
            $product->update(['ghl_image_url' => $product->image]);

            return $product->image;
        }

        $rawImage = $product->image;

        // Resolve the storage-relative path (e.g. /storage/products/xxx.jpg)
        // to an absolute filesystem path via Storage::path()
        if (str_starts_with($rawImage, '/storage/')) {
            $storageDisk = Storage::disk('public');
            $relativePath = ltrim(substr($rawImage, strlen('/storage')), '/');
            $localPath = $storageDisk->path($relativePath);

            if (file_exists($localPath)) {
                try {
                    $filename = basename($localPath);
                    $mimeType = mime_content_type($localPath) ?: 'image/jpeg';

                    $uploadResponse = $this->client->uploadFile($localPath, $filename, $mimeType);

                    Log::info('GHL image upload response', [
                        'product_id' => $product->id,
                        'response' => $uploadResponse,
                    ]);

                    // GHL returns: { "uploadedFiles": { "filename.jpg": "https://cdn..." } }
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

            // Upload failed and the image is only local — do NOT send the relative path to GHL
            return null;
        }

        // Image is already a full public URL (not local storage)
        if (str_starts_with($rawImage, 'http')) {
            return $rawImage;
        }

        // Unknown format — skip rather than send garbage to GHL
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
