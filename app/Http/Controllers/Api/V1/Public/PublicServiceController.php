<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\LiveServiceResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\ServiceVariantResource;
use App\Models\Product;
use App\Services\GhlRentalGateway;
use App\Services\ProductService;
use App\Services\RentalResolver;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicServiceController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private GhlRentalGateway $gateway,
        private RentalResolver $rentalResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_merge(
            $request->only(['search', 'category_id', 'min_price', 'max_price', 'sort', 'page', 'per_page']),
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

        if ($product->tenant_id !== $tenantId || $product->status !== 'active' || $product->product_rental_id === null) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Service not found.',
            ], 404);
        }

        $product->load(['rentals', 'defaultRental', 'categories']);

        try {
            $details = $this->gateway->fetchListingBundle($product);

            if (empty($details)) {
                throw new \RuntimeException('No live GHL details available.');
            }

            $paymentsByGhlId = $this->gateway->fetchPaymentsMap($product, $details);

            return response()->json([
                'success' => true,
                'data' => new LiveServiceResource($product, $details, $paymentsByGhlId),
                'message' => 'Service retrieved.',
            ]);
        } catch (\Exception $e) {
            Log::warning('Public service show fell back to local payload', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'data' => new ServiceResource($product),
                'message' => 'Service retrieved.',
            ]);
        }
    }

    /** Live GHL detail for a single variant (product id or product_rentals id). */
    public function variant(string $id): JsonResponse
    {
        $tenantId = TenantResolver::resolveDefault();
        $resolved = $this->rentalResolver->resolve($id, $tenantId);

        if (! $resolved) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Service not found.',
            ], 404);
        }

        [$product, $rental] = $resolved;

        if ($product->status !== 'active' || $product->product_rental_id === null) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Service not found.',
            ], 404);
        }

        $product->loadMissing(['rentals']);

        try {
            $enriched = $this->gateway->fetchEnrichedRentalDetail($rental);
            $baseRental = $product->resolveBaseRental();

            return response()->json([
                'success' => true,
                'data' => ServiceVariantResource::fromDetail(
                    $product,
                    $rental,
                    $enriched['detail'],
                    $baseRental,
                    $enriched['payments'],
                ),
                'message' => 'Variant retrieved.',
            ]);
        } catch (\Exception $e) {
            Log::warning('Public variant detail fetch failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Live availability is temporarily unavailable. Please try again.',
            ], 422);
        }
    }
}
