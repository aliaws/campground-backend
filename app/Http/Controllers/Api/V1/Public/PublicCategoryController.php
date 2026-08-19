<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\EngageCategory;
use App\Models\EngageOrganizationLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Public browsing only ever wants categories a guest could actually
        // click into and see something — an empty category (no active,
        // in-store products) is just dead-end noise in a filter list. Staff
        // management (CategoryController::index()) deliberately keeps
        // showing empty ones, since that's exactly where an admin would go
        // to add products to them.
        $browsableProducts = fn ($q) => $q->where('status', 'active')->where('available_in_store', true);

        // No single default org — aggregates every (non-blocked)
        // organization's categories together (user-directed, 2026-08-19:
        // "each organization can have different categories... why not all
        // rental services"). Deliberately NOT merged/deduplicated by name
        // across organizations — checkout requires every cart item to
        // belong to the same organization (see PublicShopController), so
        // collapsing two different orgs' "Snacks" into one filter chip
        // would just make that cross-org-cart rejection more confusing.
        $activeIds = EngageOrganizationLocation::active()->pluck('id');
        $requestedIds = $request->input('organization_ids', []);
        if (! empty($requestedIds) && is_array($requestedIds)) {
            $activeIds = $activeIds->intersect($requestedIds)->values();
        }

        $query = EngageCategory::where('is_active', true)
            ->whereIn('engage_organization_location_id', $activeIds)
            ->whereHas('products', $browsableProducts)
            ->withCount(['products' => $browsableProducts]);

        if ($request->filled('industry_type')) {
            $query->where('industry_type', $request->industry_type);
        }

        // Homepage "Shop by Category" showcase — curated subset, not every
        // POS category (the Shop page's own filter list shows all of them).
        if ($request->boolean('homepage_only')) {
            $query->where('show_on_homepage', true);
        }

        $categories = $query->orderBy('sort_order')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => CategoryResource::collection($categories),
            'message' => 'Categories retrieved.',
        ]);
    }
}
