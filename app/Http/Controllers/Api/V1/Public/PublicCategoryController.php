<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\EngageCategory;
use App\Services\OrganizationLocationResolver;
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

        $query = EngageCategory::where('engage_organization_location_id', OrganizationLocationResolver::resolveDefaultLocationId())
            ->where('is_active', true)
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
