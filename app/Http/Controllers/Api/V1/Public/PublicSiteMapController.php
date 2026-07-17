<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteMapResource;
use App\Models\SiteMap;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;

class PublicSiteMapController extends Controller
{
    public function index(): JsonResponse
    {
        $maps = SiteMap::where('tenant_id', TenantResolver::resolveDefault())
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => SiteMapResource::collection($maps),
            'message' => 'Maps retrieved.',
        ]);
    }

    public function show(SiteMap $siteMap): JsonResponse
    {
        if ($siteMap->tenant_id !== TenantResolver::resolveDefault()) {
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
