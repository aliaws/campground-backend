<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteMapElementRequest;
use App\Http\Requests\UpdateSiteMapElementRequest;
use App\Http\Resources\SiteMapElementResource;
use App\Models\SiteMap;
use App\Models\SiteMapElement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteMapElementController extends Controller
{
    public function store(StoreSiteMapElementRequest $request, SiteMap $siteMap): JsonResponse
    {
        if ($siteMap->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Map not found.'], 404);
        }

        $element = $siteMap->elements()->create(
            $request->validated() + ['tenant_id' => $request->user()->tenant_id]
        );

        // Reload from the DB: any field the request omitted (width, height,
        // rotation, opacity, z_index, is_visible) was filled in by the column's
        // DB default at insert time, but create()'s in-memory model doesn't know
        // that until refreshed — without this, the response would show null for
        // fields that actually have sensible defaults.
        return response()->json([
            'success' => true,
            'data' => new SiteMapElementResource($element->fresh()->load('productRental.product')),
            'message' => 'Element added.',
        ], 201);
    }

    public function update(UpdateSiteMapElementRequest $request, SiteMapElement $element): JsonResponse
    {
        if ($element->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Element not found.'], 404);
        }

        $element->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new SiteMapElementResource($element->fresh()->load('productRental.product')),
            'message' => 'Element updated.',
        ]);
    }

    public function destroy(Request $request, SiteMapElement $element): JsonResponse
    {
        if ($element->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Element not found.'], 404);
        }

        $element->delete();

        return response()->json(['success' => true, 'message' => 'Element removed.']);
    }
}
