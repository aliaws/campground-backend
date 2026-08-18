<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicProductResource;
use App\Services\OrganizationLocationResolver;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the guest-facing "Shop" page — browsing standalone POS goods
 * (non-rental products), the sibling of PublicServiceController for
 * rentals. Read-only, unauthenticated, rate-limited under the same
 * customer-browse throttle group as the rest of /public/*.
 */
class PublicProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'engage_organization_location_id' => OrganizationLocationResolver::resolveDefaultLocationId(),
            'search' => $request->input('search'),
            'category_ids' => $request->input('category_ids', []),
            'min_price' => $request->input('min_price'),
            'max_price' => $request->input('max_price'),
            'available_only' => $request->boolean('available_only'),
            'sort' => $request->input('sort'),
            'page' => $request->input('page'),
            'per_page' => $request->input('per_page'),
        ];

        $products = $this->productService->listStorefront($filters);

        return response()->json([
            'success' => true,
            'data' => [
                'data' => PublicProductResource::collection($products),
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
}
