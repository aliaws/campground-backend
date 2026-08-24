<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicServiceCategoryResource;
use App\Models\EngageOrganizationLocation;
use App\Models\EngageProductRentalCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mirrors PublicCategoryController — active-only, no auth, aggregated
 * across every organization, and (like that controller) excludes
 * categories with no browsable data — a guest-facing filter list
 * shouldn't offer a dead-end category with nothing behind it.
 *
 * 2026-08-19: unlike PublicCategoryController (Shop), rows here ARE
 * deduplicated by name for display — a booking is single-item, so there's
 * no cross-org-cart-collision reason to keep two organizations'
 * identically-named "Tent Sites" categories visually distinct the way
 * Shop's checkout flow needs. See PublicServiceCategoryResource::groupByName().
 */
class PublicServiceCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // A rental only counts as "browsable" if its own listing Product is
        // active too — a rental row can exist locally with its parent
        // Product archived/draft, which listServices() (what the homepage
        // actually queries) already excludes.
        $browsableRentals = fn ($q) => $q->whereHas('product', fn ($p) => $p->where('status', 'active'));

        // No single default org — see PublicCategoryController's doc
        // comment for the full reasoning (user-directed, 2026-08-19).
        $activeIds = EngageOrganizationLocation::active()->pluck('id');

        // Narrows the aggregated category list to a caller-picked subset of
        // organizations (2026-08-19, the homepage's Organization filter) —
        // same intersect-with-active pattern as ProductService::
        // scopeToLocationOrAllActiveOrgs()/PublicSiteMapController, so a
        // blocked/uninstalled org id can never leak its categories back in.
        $requestedIds = $request->input('organization_ids', []);
        if (! empty($requestedIds) && is_array($requestedIds)) {
            $activeIds = $activeIds->intersect($requestedIds)->values();
        }

        $categories = EngageProductRentalCategory::where('is_active', true)
            ->whereIn('engage_organization_location_id', $activeIds)
            ->whereHas('rentals', $browsableRentals)
            ->withCount(['rentals' => $browsableRentals])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => PublicServiceCategoryResource::collection(
                PublicServiceCategoryResource::groupByName($categories)
            ),
            'message' => 'Service categories retrieved.',
        ]);
    }
}
