<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LiveServiceResource;
use App\Http\Resources\ServiceResource;
use App\Models\Product;
use App\Services\GhlRentalGateway;
use App\Services\GhlServiceSyncService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ServiceController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private GhlServiceSyncService $ghlServiceSyncService,
        private GhlRentalGateway $gateway,
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
        if ($product->tenant_id !== $request->user()->tenant_id || $product->product_rental_id === null) {
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
            Log::warning('Staff service show fell back to local payload', [
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
