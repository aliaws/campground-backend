<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\EngageOrganizationLocation;
use App\Models\EngageProductRentalCategory;
use Illuminate\Http\JsonResponse;

/** Mirrors PublicCategoryController — active-only, no auth, aggregated across every organization, and (like that controller) excludes categories with no browsable data — a guest-facing filter list shouldn't offer a dead-end category with nothing behind it. */
class PublicServiceCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        // A rental only counts as "browsable" if its own listing Product is
        // active too — a rental row can exist locally with its parent
        // Product archived/draft, which listServices() (what the homepage
        // actually queries) already excludes.
        $browsableRentals = fn ($q) => $q->whereHas('product', fn ($p) => $p->where('status', 'active'));

        // No single default org — see PublicCategoryController's doc
        // comment for the full reasoning (user-directed, 2026-08-19).
        $categories = EngageProductRentalCategory::where('is_active', true)
            ->whereIn('engage_organization_location_id', EngageOrganizationLocation::active()->pluck('id'))
            ->whereHas('rentals', $browsableRentals)
            ->withCount(['rentals' => $browsableRentals])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ServiceCategoryResource::collection($categories),
            'message' => 'Service categories retrieved.',
        ]);
    }
}
