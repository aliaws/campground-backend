<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
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
            'data' => [
                'data' => ProductResource::collection($products),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'next_page_url' => $products->nextPageUrl(),
                'prev_page_url' => $products->previousPageUrl(),
            ],
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
        $product->load(['categories', 'rentals', 'defaultRental']);

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

    public function syncToGhl(Product $product): JsonResponse
    {
        try {
            $product = $this->ghlProductSyncService->syncProductToGhl($product);

            return response()->json([
                'success' => true,
                'data' => new ProductResource($product->load(['categories'])),
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
