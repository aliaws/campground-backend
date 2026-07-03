<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePriceRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductPriceResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\GhlProductSyncService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private GhlProductSyncService $ghlProductSyncService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $products = $this->productService->list(
            array_merge($request->all(), ['tenant_id' => $request->user()->tenant_id])
        );

        return response()->json([
            'success' => true,
            'data' => ProductResource::collection($products),
            'message' => 'Products retrieved.',
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create(
            $request->validated() + ['tenant_id' => $request->user()->tenant_id]
        );

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product created.',
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['categories', 'prices', 'variants.options', 'amenities', 'features']);

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product retrieved.',
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->productService->update($product, $request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product updated.',
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->productService->delete($product);

        return response()->json(['success' => true, 'message' => 'Product deleted.']);
    }

    public function uploadImage(Request $request, Product $product): JsonResponse
    {
        $request->validate(['image' => 'required|image|max:2048']);
        $product = $this->productService->uploadImage($product, $request->file('image'));

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product image updated.',
        ]);
    }

    // ── Prices ────────────────────────────────────────────────────────────────

    public function allPrices(Request $request): JsonResponse
    {
        $query = ProductPrice::with('product')
            ->whereHas('product', fn ($q) => $q->where('tenant_id', $request->user()->tenant_id))
            ->where('deleted', false);

        if ($request->input('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$search}%"));
            });
        }

        $prices = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $prices->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'amount' => (float) $p->amount,
                'compare_at_price' => $p->compare_at_price !== null ? (float) $p->compare_at_price : null,
                'currency' => $p->currency,
                'variant_option_ids' => $p->variant_option_ids,
                'track_inventory' => $p->track_inventory,
                'available_quantity' => $p->available_quantity,
                'recurring_interval' => $p->recurring_interval,
                'recurring_interval_count' => $p->recurring_interval_count,
                'sku' => $p->sku,
                'deleted' => $p->deleted,
                'engage_price_id' => $p->engage_price_id,
                'engage_sync_status' => $p->engage_sync_status,
                'sync_error_message' => $p->sync_error_message,
                'product_id' => $p->product_id,
                'product_name' => $p->product?->name,
                'product_engage_id' => $p->product?->engage_product_id,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at,
            ]),
            'message' => 'All prices retrieved.',
        ]);
    }

    public function prices(Product $product): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ProductPriceResource::collection(
                $product->prices()->where('deleted', false)->get()
            ),
            'message' => 'Prices retrieved.',
        ]);
    }

    public function storePrice(StorePriceRequest $request, Product $product): JsonResponse
    {
        $price = $this->productService->addPrice($product, $request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProductPriceResource($price),
            'message' => 'Price created.',
        ], 201);
    }

    public function updatePrice(StorePriceRequest $request, Product $product, ProductPrice $price): JsonResponse
    {
        $price = $this->productService->updatePrice($price, $request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProductPriceResource($price),
            'message' => 'Price updated.',
        ]);
    }

    // ── Categories ────────────────────────────────────────────────────────────

    public function attachCategories(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'category_ids' => ['required', 'array'],
            'category_ids.*' => ['string', 'exists:categories,id'],
        ]);

        $product->categories()->sync($request->input('category_ids'));

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product->fresh()->load('categories')),
            'message' => 'Product categories updated.',
        ]);
    }

    // ── GHL sync ──────────────────────────────────────────────────────────────

    public function syncToGhl(Product $product): JsonResponse
    {
        try {
            $product = $this->ghlProductSyncService->syncProductToGhl($product);

            return response()->json([
                'success' => true,
                'data' => new ProductResource(
                    $product->load(['categories', 'prices', 'variants.options'])
                ),
                'message' => 'Product synced to GHL.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: '.$e->getMessage(),
            ], 422);
        }
    }

    public function pullFromGhl(Product $product): JsonResponse
    {
        try {
            $product = $this->ghlProductSyncService->pullFromGhl($product);

            return response()->json([
                'success' => true,
                'data' => new ProductResource($product),
                'message' => 'Product pulled from GHL successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pull failed: '.$e->getMessage(),
            ], 422);
        }
    }

    public function bulkSync(Request $request): JsonResponse
    {
        $results = $this->ghlProductSyncService->bulkSyncProducts($request->user()->tenant_id);

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Synced {$results['synced']} products, {$results['errors']} errors.",
        ]);
    }

    public function bulkPull(Request $request): JsonResponse
    {
        $results = $this->ghlProductSyncService->bulkPullFromGhl($request->user()->tenant_id);

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Pulled {$results['pulled']} products from GHL ({$results['created']} new), {$results['errors']} errors.",
        ]);
    }
}
