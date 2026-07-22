<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Category;
use App\Models\Product;
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
    ) {}

    public function syncProductToGhl(Product $product): Product
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

    public function pullFromGhl(Product $product): Product
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

        $this->pullDefaultPriceFromGhl($product, $query);

        return $product->fresh()->load(['categories']);
    }

    public function deleteProductFromGhl(Product $product): void
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

    public function bulkSyncProducts(string $tenantId): array
    {
        $results = ['synced' => 0, 'errors' => 0, 'error_details' => []];

        $products = Product::byTenant($tenantId)
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

    public function bulkPullFromGhl(string $tenantId): array
    {
        $results = ['pulled' => 0, 'created' => 0, 'errors' => 0, 'error_details' => []];

        $rentalProductIds = array_flip($this->serviceSync->fetchRentalProductIds());

        try {
            foreach ($this->fetchAllGhlProducts() as $ghlProduct) {
                $ghlId = $ghlProduct['_id'] ?? $ghlProduct['id'] ?? null;
                if ($ghlId !== null && isset($rentalProductIds[$ghlId])) {
                    continue;
                }

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

    /**
     * Pull GHL "Collections" (Categories) into the local catalog — the
     * category-typed sibling of bulkPullFromGhl() for products. Existing
     * categories (matched by engage_collection_id) are updated in place;
     * everything else is created fresh, same "create local stub if missing,
     * update if not" pattern.
     */
    public function pullCategoriesFromGhl(string $tenantId): array
    {
        $results = ['pulled' => 0, 'created' => 0, 'errors' => 0, 'error_details' => []];

        try {
            foreach ($this->fetchAllGhlCollections() as $ghlCollection) {
                $ghlId = $ghlCollection['_id'] ?? $ghlCollection['id'] ?? null;

                if (! $ghlId) {
                    continue;
                }

                $data = [
                    'name' => $ghlCollection['name'] ?? 'Untitled',
                    'slug' => $ghlCollection['slug'] ?? null,
                    'image' => empty($ghlCollection['image']) ? null : $ghlCollection['image'],
                    'engage_collection_id' => $ghlId,
                    'engage_sync_status' => 'synced',
                    'engage_last_synced_at' => now(),
                    'tenant_id' => $tenantId,
                ];

                $category = Category::where('tenant_id', $tenantId)
                    ->where('engage_collection_id', $ghlId)
                    ->first();

                if ($category) {
                    $category->update($data);
                } else {
                    Category::create($data + ['is_active' => true, 'sort_order' => 0]);
                    $results['created']++;
                }

                $results['pulled']++;
            }
        } catch (\Exception $e) {
            $results['errors']++;
            $results['error_details'][] = ['error' => 'GHL collection list fetch failed: '.$e->getMessage()];
            Log::error('GHL collection list fetch failed', ['error' => $e->getMessage()]);
        }

        return $results;
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

    private function syncDefaultPriceToGhl(Product $product): void
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

    private function pullDefaultPriceFromGhl(Product $product, array $query): void
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

    private function createLocalStubIfMissing(array $ghlProduct, string $tenantId): bool
    {
        $ghlId = $ghlProduct['_id'] ?? $ghlProduct['id'] ?? null;

        if (! $ghlId) {
            return false;
        }

        $exists = Product::withTrashed()
            ->byTenant($tenantId)
            ->where('ghl_product_id', $ghlId)
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
            'ghl_product_id' => $ghlId,
            'engage_sync_status' => 'pending',
            'tenant_id' => $tenantId,
        ]);

        return true;
    }

    private function uploadImageToGhl(Product $product): ?string
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
