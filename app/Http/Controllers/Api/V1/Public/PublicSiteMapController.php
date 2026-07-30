<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteMapResource;
use App\Models\SiteMap;
use App\Services\OrganizationLocationResolver;
use Illuminate\Http\JsonResponse;

class PublicSiteMapController extends Controller
{
    public function index(): JsonResponse
    {
        $locationId = OrganizationLocationResolver::resolveDefaultLocationId();

        // Customers only ever see ONE map — whichever the staff builder has
        // marked as default. Falls back to the oldest map for locations that
        // haven't explicitly picked a default yet (e.g. before this feature
        // existed), so the customer-facing page never shows a blank state by default.
        $map = SiteMap::where('engage_organization_location_id', $locationId)->where('is_default', true)->first()
            ?? SiteMap::where('engage_organization_location_id', $locationId)->oldest()->first();

        $maps = $map ? collect([$map]) : collect();

        return response()->json([
            'success' => true,
            'data' => SiteMapResource::collection($maps),
            'message' => 'Maps retrieved.',
        ]);
    }

    public function show(SiteMap $siteMap): JsonResponse
    {
        if ($siteMap->engage_organization_location_id !== OrganizationLocationResolver::resolveDefaultLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Map not found.'], 404);
        }

        $siteMap->load(['elements' => function ($query) {
            $query->where('is_visible', true);
        }, 'elements.productRental.product', 'elements.iconType']);

        return response()->json([
            'success' => true,
            'data' => new SiteMapResource($siteMap),
            'message' => 'Map retrieved.',
        ]);
    }
}
