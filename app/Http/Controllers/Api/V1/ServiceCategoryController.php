<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use App\Services\GhlServiceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mirrors CategoryController (Product Categories), scoped to services/
 * rentals. Deliberately no syncToGhl()/bulkSync() — unlike Category, there's
 * no "push a locally-created category into GHL" use case here (a rental can
 * only ever be created via the GHL services pull, never through this app —
 * see StoreProductRequest's own note on that), so this controller only ever
 * pulls from GHL, never pushes to it.
 */
class ServiceCategoryController extends Controller
{
    public function __construct(
        private GhlServiceSyncService $ghlServiceSyncService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $categories = ServiceCategory::where('tenant_id', $request->user()->tenant_id)
            ->withCount('rentals')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ServiceCategoryResource::collection($categories),
            'message' => 'Service categories retrieved.',
        ]);
    }

    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        $category = ServiceCategory::create($data);

        return response()->json([
            'success' => true,
            'data' => new ServiceCategoryResource($category),
            'message' => 'Service category created.',
        ], 201);
    }

    public function show(Request $request, ServiceCategory $serviceCategory): JsonResponse
    {
        if ($serviceCategory->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Service category not found.'], 404);
        }

        $serviceCategory->loadCount('rentals');

        return response()->json([
            'success' => true,
            'data' => new ServiceCategoryResource($serviceCategory),
            'message' => 'Service category retrieved.',
        ]);
    }

    public function update(StoreServiceCategoryRequest $request, ServiceCategory $serviceCategory): JsonResponse
    {
        if ($serviceCategory->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Service category not found.'], 404);
        }

        $serviceCategory->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new ServiceCategoryResource($serviceCategory->fresh()->loadCount('rentals')),
            'message' => 'Service category updated.',
        ]);
    }

    public function destroy(Request $request, ServiceCategory $serviceCategory): JsonResponse
    {
        if ($serviceCategory->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Service category not found.'], 404);
        }

        // No pivot to detach — a rental just falls back to "no category"
        // (service_category_id is left as-is on product_rentals; the
        // BelongsTo relation simply resolves to null once no ServiceCategory
        // row matches that ghl_category_id anymore).
        $serviceCategory->delete();

        return response()->json(['success' => true, 'message' => 'Service category deleted.']);
    }

    public function pullFromGhl(Request $request): JsonResponse
    {
        try {
            $results = $this->ghlServiceSyncService->pullServiceCategories($request->user()->tenant_id);
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
            'message' => "Pulled {$results['pulled']} service categories from Lead Connector ({$results['created']} new), {$results['errors']} errors.",
        ]);
    }
}
