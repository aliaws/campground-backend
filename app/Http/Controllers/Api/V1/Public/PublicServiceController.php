<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Product;
use App\Services\ProductService;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicServiceController extends Controller
{
    public function __construct(
        private ProductService $productService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_merge(
            $request->only(['search', 'category_id', 'date_from', 'date_to', 'min_price', 'max_price', 'sort', 'page', 'per_page']),
            ['tenant_id' => TenantResolver::resolveDefault()]
        );

        $services = $this->productService->listServices($filters);

        return response()->json([
            'success' => true,
            'data' => [
                'data' => ServiceResource::collection($services),
                'current_page' => $services->currentPage(),
                'last_page' => $services->lastPage(),
                'per_page' => $services->perPage(),
                'total' => $services->total(),
                'next_page_url' => $services->nextPageUrl(),
                'prev_page_url' => $services->previousPageUrl(),
            ],
            'message' => 'Services retrieved.',
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        $tenantId = TenantResolver::resolveDefault();

        if ($product->tenant_id !== $tenantId || $product->status !== 'active' || $product->parent_product_id !== null) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Service not found.',
            ], 404);
        }

        $product->load(['serviceVariants', 'categories', 'amenities', 'features', 'prices']);

        return response()->json([
            'success' => true,
            'data' => new ServiceResource($product),
            'message' => 'Service retrieved.',
        ]);
    }
}
