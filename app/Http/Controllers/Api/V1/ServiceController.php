<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Product;
use App\Services\GhlServiceSyncService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private GhlServiceSyncService $ghlServiceSyncService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'tenant_id' => $request->user()->tenant_id,
        ]);

        $services = $this->productService->listServices($filters);

        return response()->json([
            'success' => true,
            'data' => ServiceResource::collection($services),
            'message' => 'Services retrieved.',
        ]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $product->load([
            'rental', 'parent', 'serviceVariants', 'categories', 'amenities', 'features', 'prices',
        ]);

        if ($product->parent_product_id !== null) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Use the base rental listing ID, not a variant row.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new ServiceResource($product),
            'message' => 'Service retrieved.',
        ]);
    }

    /** Pull all Calendar Services/Rentals (with variants + pricing rules) from GHL. */
    public function pullFromGhl(Request $request): JsonResponse
    {
        try {
            $results = $this->ghlServiceSyncService->pullServices($request->user()->tenant_id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Pulled {$results['pulled']} services from GHL, {$results['errors']} errors.",
        ]);
    }
}
