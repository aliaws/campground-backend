<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Support\Facades\Log;

class GhlProductSyncService
{
    public function __construct(
        private GhlClient $client,
    ) {}

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
            'availableInStore' => $product->available_in_store ?? true,
        ];

        if ($product->image) {
            $imageUrl = $product->image;
            if (!str_starts_with($imageUrl, 'http')) {
                $imageUrl = config('app.url') . $imageUrl;
            }
            $payload['image'] = $imageUrl;
        }

        if ($product->medias && is_array($product->medias)) {
            $payload['medias'] = array_map(function ($media) {
                if (isset($media['url']) && !str_starts_with($media['url'], 'http')) {
                    $media['url'] = config('app.url') . $media['url'];
                }
                return $media;
            }, $product->medias);
        }

        $categoryIds = $product->categories()->pluck('engage_collection_id')->filter()->values()->toArray();
        if (!empty($categoryIds)) {
            $payload['collectionIds'] = $categoryIds;
        }

        try {
            if ($product->engage_product_id) {
                $response = $this->client->put("products/{$product->engage_product_id}", $payload);
            } else {
                $response = $this->client->post('products/', $payload);
            }

            Log::info('GHL product sync response', ['product' => $product->name, 'response' => $response]);

            $ghlId = $this->extractId($response, $product->engage_product_id);

            $product->update([
                'engage_product_id' => $ghlId,
                'engage_sync_status' => 'synced',
                'engage_last_synced_at' => now(),
            ]);

            $this->syncPricesForProduct($product->fresh());

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

    public function deleteProductFromGhl(Product $product): void
    {
        if (!$product->engage_product_id) {
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

    public function syncPriceToGhl(ProductPrice $price): ProductPrice
    {
        $product = $price->product;

        if (!$product->engage_product_id) {
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

        try {
            $endpoint = "products/{$product->engage_product_id}/price/";

            if ($price->engage_price_id) {
                $response = $this->client->put("{$endpoint}{$price->engage_price_id}", $payload);
            } else {
                $response = $this->client->post($endpoint, $payload);
            }

            Log::info('GHL price sync response', ['price' => $price->name, 'response' => $response]);

            $ghlPriceId = $this->extractId($response, $price->engage_price_id);

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
        if (!$price->engage_price_id) {
            return;
        }

        $product = $price->product;

        if (!$product->engage_product_id) {
            return;
        }

        try {
            $this->client->delete("products/{$product->engage_product_id}/price/{$price->engage_price_id}");

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
            if ($category->engage_collection_id) {
                $response = $this->client->put("products/collections/{$category->engage_collection_id}", $payload);
            } else {
                $response = $this->client->post('products/collections/', $payload);
            }

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
        if (!$category->engage_collection_id) {
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

    public function syncPricesForProduct(Product $product): void
    {
        foreach ($product->prices()->where('deleted', false)->get() as $price) {
            try {
                $this->syncPriceToGhl($price);
            } catch (\Exception $e) {
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

            usleep(100000);
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

            usleep(100000);
        }

        return $results;
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
