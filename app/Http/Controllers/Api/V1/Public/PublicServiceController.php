<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicLiveServiceResource;
use App\Http\Resources\PublicServiceResource;
use App\Http\Resources\ServiceVariantResource;
use App\Models\EngageProduct;
use App\Services\GhlLocationContext;
use App\Services\GhlRentalGateway;
use App\Services\ProductService;
use App\Services\RentalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicServiceController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private GhlRentalGateway $gateway,
        private RentalResolver $rentalResolver,
        private GhlLocationContext $ghlLocationContext,
    ) {}

    /**
     * No engage_organization_location_id filter — the public browse list
     * aggregates every (non-blocked) organization's rentals together, not
     * just one default org (user-directed, 2026-08-19). See
     * ProductService::scopeToLocationOrAllActiveOrgs()'s doc comment.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'category_id', 'service_category_id', 'min_price', 'max_price', 'sort', 'page', 'per_page']);
        $filters['service_category_ids'] = $request->input('service_category_ids', []);
        $filters['organization_ids'] = $request->input('organization_ids', []);

        $services = $this->productService->listServices($filters);

        // Needed for PublicServiceResource's organization attribution below
        // — listServices() (shared with the staff ServiceController) has
        // no reason to eager-load this for POS, so it's loaded here,
        // scoped to this public controller only.
        $services->getCollection()->loadMissing('organizationLocation');

        return response()->json([
            'success' => true,
            'data' => [
                'data' => PublicServiceResource::collection($services),
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

    public function show(EngageProduct $product): JsonResponse
    {
        $organization = $product->organizationLocation;

        if ($product->status !== 'active' || ! $product->isRental()
            || ! $organization || $organization->isBlocked() || $organization->isUninstalled()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Service not found.',
            ], 404);
        }

        $product->load(['rentals.serviceCategory', 'defaultRental.serviceCategory', 'categories', 'amenities', 'features', 'organizationLocation']);

        // A guest request has no authenticated user for GhlClient to derive
        // credentials from — scope to this specific product's own
        // organization so the live detail fetch below uses the right GHL
        // token, not whichever org's token happens to resolve first.
        $this->ghlLocationContext->set($product->engage_organization_location_id);

        try {
            $details = $this->gateway->fetchListingBundle($product);

            if (empty($details)) {
                throw new \RuntimeException('No live Lead Connector details available.');
            }

            $paymentsByGhlId = $this->gateway->fetchPaymentsMap($product, $details);

            return response()->json([
                'success' => true,
                'data' => new PublicLiveServiceResource($product, $details, $paymentsByGhlId),
                'message' => 'Service retrieved.',
            ]);
        } catch (\Exception $e) {
            Log::warning('Public service show fell back to local payload', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'data' => new PublicServiceResource($product),
                'message' => 'Service retrieved.',
            ]);
        } finally {
            $this->ghlLocationContext->set(null);
        }
    }

    /** Live GHL detail for a single variant (product id or product_rentals id). */
    public function variant(string $id): JsonResponse
    {
        // No org passed — resolves globally, then the product's own org
        // (not a forced default) is what scopes the GHL call below.
        $resolved = $this->rentalResolver->resolve($id);

        if (! $resolved) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Service not found.',
            ], 404);
        }

        [$product, $rental] = $resolved;
        $organization = $product->organizationLocation;

        if ($product->status !== 'active' || ! $product->isRental()
            || ! $organization || $organization->isBlocked() || $organization->isUninstalled()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Service not found.',
            ], 404);
        }

        $product->loadMissing(['rentals']);
        $this->ghlLocationContext->set($product->engage_organization_location_id);

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
        } finally {
            $this->ghlLocationContext->set(null);
        }
    }
}
