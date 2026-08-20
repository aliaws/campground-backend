<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\EngageProduct;
use App\Services\GhlProductGateway;
use App\Services\GhlProductSyncService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private GhlProductSyncService $ghlProductSyncService,
        private GhlProductGateway $ghlProductGateway,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $products = $this->productService->list(
            array_merge($request->all(), ['engage_organization_location_id' => $request->user()->resolveOrganizationLocationId()])
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

    /**
     * Exact-match SKU lookup for the Product Sales page's barcode scanner
     * (a scanned code is looked up verbatim, not fuzzy-searched). Route must
     * be registered before GET /products/{product} in routes/api.php, or
     * Laravel's implicit route-model-binding would swallow this path as a
     * {product} id lookup and 404 before this method is ever reached.
     */
    public function lookupBySku(Request $request): JsonResponse
    {
        $sku = $request->query('sku');
        $product = $sku ? $this->productService->findBySku($request->user()->resolveOrganizationLocationId(), $sku) : null;

        return response()->json([
            'success' => true,
            'data' => $product ? new ProductResource($product) : null,
            'message' => $product ? 'Product found.' : 'No product found for that barcode.',
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create(
            $request->validated() + ['engage_organization_location_id' => $request->user()->resolveOrganizationLocationId()]
        );

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product created.',
        ], 201);
    }

    public function show(Request $request, EngageProduct $product): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        $product->load(['categories', 'rentals', 'defaultRental']);

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product retrieved.',
        ]);
    }

    public function update(UpdateProductRequest $request, EngageProduct $product): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        $product = $this->productService->update($product, $request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product updated.',
        ]);
    }

    public function destroy(Request $request, EngageProduct $product): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        $this->productService->delete($product);

        return response()->json(['success' => true, 'message' => 'Product deleted.']);
    }

    public function uploadImage(Request $request, EngageProduct $product): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        $request->validate(['image' => 'required|image|max:2048']);
        $product = $this->productService->uploadImage($product, $request->file('image'));

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product image updated.',
        ]);
    }

    /**
     * Gallery management for a service's full `images` array (Manage
     * Service's edit form) — additive, appends a new image rather than
     * replacing the cover the way uploadImage() above does.
     */
    public function addImage(Request $request, EngageProduct $product): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        $request->validate(['image' => 'required|image|max:2048']);
        $product = $this->productService->addImage($product, $request->file('image'));

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Image added.',
        ]);
    }

    public function removeImage(Request $request, EngageProduct $product, int $position): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        $product = $this->productService->removeImage($product, $position);

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Image removed.',
        ]);
    }

    public function setCoverImage(Request $request, EngageProduct $product, int $position): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        $product = $this->productService->setCoverImage($product, $position);

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Cover image updated.',
        ]);
    }

    public function attachCategories(Request $request, EngageProduct $product): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        $request->validate([
            'category_ids' => ['required', 'array'],
            'category_ids.*' => ['string', 'exists:engage_categories,id'],
        ]);

        $product->categories()->sync($request->input('category_ids'));

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product->fresh()->load('categories')),
            'message' => 'Product categories updated.',
        ]);
    }

    public function syncToGhl(Request $request, EngageProduct $product): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        try {
            $product = $this->ghlProductSyncService->syncProductToGhl($product);

            return response()->json([
                'success' => true,
                'data' => new ProductResource($product->load(['categories'])),
                'message' => 'Product synced to Lead Connector.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: '.$e->getMessage(),
            ], 422);
        }
    }

    public function pullFromGhl(Request $request, EngageProduct $product): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        try {
            $product = $this->ghlProductSyncService->pullFromGhl($product);

            return response()->json([
                'success' => true,
                'data' => new ProductResource($product),
                'message' => 'Product pulled from Lead Connector successfully.',
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
        $results = $this->ghlProductSyncService->bulkSyncProducts($request->user()->resolveOrganizationLocationId());

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Synced {$results['synced']} products, {$results['errors']} errors.",
        ]);
    }

    public function bulkPull(Request $request): JsonResponse
    {
        $results = $this->ghlProductSyncService->bulkPullFromGhl($request->user()->resolveOrganizationLocationId());

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Pulled {$results['pulled']} products from Lead Connector ({$results['created']} new), {$results['errors']} errors.",
        ]);
    }

    /**
     * Backfills a SKU (and therefore a printable Code 39 barcode) onto every
     * non-rental goods product in the location that doesn't have one yet —
     * covers products created before SKU auto-generation existed, or via any
     * path that bypassed it. Rentals/services have no SKU concept and are
     * never touched (see ProductService::generateMissingSkus()).
     */
    public function generateSkus(Request $request): JsonResponse
    {
        $results = $this->productService->generateMissingSkus($request->user()->resolveOrganizationLocationId());

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => $results['updated'] > 0
                ? "Generated barcodes for {$results['updated']} product(s)."
                : 'Every product already has a barcode.',
        ]);
    }

    /**
     * Live GHL price + stock for a product's default price — used by the
     * POS Product Sales page to show real-time availability before a sale.
     * Never persisted locally (same "compute live, don't store" pattern as
     * GhlRentalGateway). Returns null data when the product isn't linked to
     * a GHL product yet.
     */
    public function ghlStock(Request $request, EngageProduct $product): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $product)) {
            return $response;
        }

        try {
            $detail = $this->ghlProductGateway->fetchDefaultPriceDetail($product);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Live stock check is temporarily unavailable. Please try again.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $detail,
            'message' => $detail ? 'Live stock retrieved.' : 'Product is not linked to a Lead Connector product yet.',
        ]);
    }

    /**
     * Route-model binding (EngageProduct $product) fetches by id alone, with no
     * tenant scoping — every action taking a bound Product must call this
     * first, or a staff member from a different organization could view/
     * modify/delete/sync another organization's product just by knowing or
     * guessing its id.
     */
    private function denyUnlessOwned(Request $request, EngageProduct $product): ?JsonResponse
    {
        if ($product->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Product not found.'], 404);
        }

        return null;
    }
}
