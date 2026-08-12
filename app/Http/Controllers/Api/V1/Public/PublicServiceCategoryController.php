<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use App\Services\OrganizationLocationResolver;
use Illuminate\Http\JsonResponse;

/** Mirrors PublicCategoryController — active-only, no auth. */
class PublicServiceCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = ServiceCategory::where('engage_organization_location_id', OrganizationLocationResolver::resolveDefaultLocationId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ServiceCategoryResource::collection($categories),
            'message' => 'Service categories retrieved.',
        ]);
    }
}
