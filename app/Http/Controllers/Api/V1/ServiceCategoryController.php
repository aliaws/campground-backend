<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\ProductRentalCategory;
use App\Services\GhlServiceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mirrors CategoryController (Product Categories), scoped to services/
 * rentals.
 *
 * **2026-08-12 revision**: this controller's docblock previously said it
 * was deliberately pull-only — "no push a locally-created category into
 * GHL use case here" — reasoning that only ever applied to *rentals*
 * themselves (which really can only be created via the GHL pull, see
 * StoreProductRequest's own note). Service Categories are a different,
 * standalone taxonomy an admin can legitimately create/rename/delete
 * locally, and now push those changes outward too — see
 * GhlServiceSyncService::syncServiceCategoryToGhl()/
 * deleteServiceCategoryFromGhl() for the full design, including a real,
 * live-verified current limitation (write scope not yet authorized on this
 * connection). store()/update()/destroy() below never let that failure
 * block the local operation — see each method's own comment for why.
 */
class ServiceCategoryController extends Controller
{
    public function __construct(
        private GhlServiceSyncService $ghlServiceSyncService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $categories = ProductRentalCategory::where('engage_organization_location_id', $request->user()->resolveOrganizationLocationId())
            ->withCount('rentals')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ServiceCategoryResource::collection($categories),
            'message' => 'Service categories retrieved.',
        ]);
    }

    /**
     * Creates the local row unconditionally first — this always succeeds
     * exactly as it did before this feature, so a GHL-side failure below
     * can never regress the local CRUD that already worked. The outbound
     * push is then attempted best-effort: on success the row already
     * carries its real `ghl_category_id`/`synced` status (set inside
     * syncServiceCategoryToGhl() itself); on failure the row stays
     * `error` and a clear, factual message is returned alongside the
     * still-201 response so the frontend can surface a warning rather than
     * silently reporting full success. The frontend can retry later via
     * `POST /service-categories/{id}/sync-ghl` (syncToGhl() below), or the
     * category will self-heal on the next "Pull from Lead Connector" if a
     * matching category was independently created in GHL in the meantime.
     */
    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['engage_organization_location_id'] = $request->user()->resolveOrganizationLocationId();

        $category = ProductRentalCategory::create($data);

        $syncError = $this->pushToGhl($category);

        return response()->json([
            'success' => true,
            'data' => new ServiceCategoryResource($category->fresh()),
            'message' => $syncError
                ? "Service category created locally, but Lead Connector sync failed: {$syncError}"
                : 'Service category created and synced to Lead Connector.',
        ], 201);
    }

    public function show(Request $request, ProductRentalCategory $serviceCategory): JsonResponse
    {
        if ($serviceCategory->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Service category not found.'], 404);
        }

        $serviceCategory->loadCount('rentals');

        return response()->json([
            'success' => true,
            'data' => new ServiceCategoryResource($serviceCategory),
            'message' => 'Service category retrieved.',
        ]);
    }

    /** Same "local always succeeds, GHL push is best-effort" contract as store() above. */
    public function update(StoreServiceCategoryRequest $request, ProductRentalCategory $serviceCategory): JsonResponse
    {
        if ($serviceCategory->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Service category not found.'], 404);
        }

        $serviceCategory->update($request->validated());

        $syncError = $this->pushToGhl($serviceCategory);

        return response()->json([
            'success' => true,
            'data' => new ServiceCategoryResource($serviceCategory->fresh()->loadCount('rentals')),
            'message' => $syncError
                ? "Service category updated locally, but Lead Connector sync failed: {$syncError}"
                : 'Service category updated and synced to Lead Connector.',
        ]);
    }

    /**
     * Deletes GHL-side first (when the category was ever linked), then
     * local — the opposite ordering from store()/update(), deliberately:
     * a delete is the one operation here with no later retry path once the
     * local row is gone, so attempting the GHL side while there's still a
     * local row to fall back on is safer than the reverse. If the GHL
     * delete fails, the local delete still proceeds anyway (same
     * "never let a GHL failure block already-working local functionality"
     * rule as store()/update() — the alternative, refusing to delete
     * locally at all while write access is scope-blocked, would make this
     * button appear completely broken under today's real connection state)
     * — but the response says so plainly rather than reporting a clean
     * success, so a staff member knows to double check the Lead Connector
     * side rather than assuming it's gone from both.
     */
    public function destroy(Request $request, ProductRentalCategory $serviceCategory): JsonResponse
    {
        if ($serviceCategory->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Service category not found.'], 404);
        }

        $syncError = null;
        try {
            $this->ghlServiceSyncService->deleteServiceCategoryFromGhl($serviceCategory);
        } catch (\Exception $e) {
            $syncError = $e->getMessage();
        }

        // No pivot to detach — a rental just falls back to "no category"
        // (service_category_id is left as-is on product_rentals; the
        // BelongsTo relation simply resolves to null once no ServiceCategory
        // row matches that ghl_category_id anymore).
        $serviceCategory->delete();

        return response()->json([
            'success' => true,
            'message' => $syncError
                ? "Service category deleted locally, but removing it from Lead Connector failed: {$syncError}"
                : 'Service category deleted from both the local database and Lead Connector.',
        ]);
    }

    /**
     * Manual retry for a category stuck `error`/`not_synced` (e.g. because
     * write scope wasn't authorized yet at creation time) — mirrors
     * CategoryController::syncToGhl()'s single-record retry button.
     */
    public function syncToGhl(Request $request, ProductRentalCategory $serviceCategory): JsonResponse
    {
        if ($serviceCategory->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Service category not found.'], 404);
        }

        try {
            $category = $this->ghlServiceSyncService->syncServiceCategoryToGhl($serviceCategory);

            return response()->json([
                'success' => true,
                'data' => new ServiceCategoryResource($category->loadCount('rentals')),
                'message' => 'Service category synced to Lead Connector.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: '.$e->getMessage(),
            ], 422);
        }
    }

    /** @return string|null the sync error message, or null on success */
    private function pushToGhl(ProductRentalCategory $serviceCategory): ?string
    {
        try {
            $this->ghlServiceSyncService->syncServiceCategoryToGhl($serviceCategory);

            return null;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function pullFromGhl(Request $request): JsonResponse
    {
        try {
            $results = $this->ghlServiceSyncService->pullServiceCategories($request->user()->resolveOrganizationLocationId());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        // `note` is set only on a 0-pulled, 0-error run — e.g. "Lead
        // Connector has no categories configured for this location yet"
        // or an unrecognized-response-shape diagnostic — folded into the
        // same message the Pull button's Alert already shows, so a silent
        // "0 pulled, no visible error" outcome always says *why*.
        $message = "Pulled {$results['pulled']} service categories from Lead Connector ({$results['created']} new), {$results['errors']} errors.";
        if (! empty($results['deleted'])) {
            $noun = $results['deleted'] === 1 ? 'category' : 'categories';
            $verb = $results['deleted'] === 1 ? 'was' : 'were';
            $message .= " {$results['deleted']} {$noun} no longer in Lead Connector {$verb} removed.";
        }
        if (! empty($results['note'])) {
            $message .= ' '.$results['note'];
        }

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => $message,
        ]);
    }
}
