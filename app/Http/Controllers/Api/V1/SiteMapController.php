<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteMapRequest;
use App\Http\Resources\SiteMapResource;
use App\Models\SiteMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SiteMapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $maps = SiteMap::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => SiteMapResource::collection($maps),
            'message' => 'Maps retrieved.',
        ]);
    }

    public function store(StoreSiteMapRequest $request): JsonResponse
    {
        $map = SiteMap::create($request->validated() + ['tenant_id' => $request->user()->tenant_id]);

        return response()->json([
            'success' => true,
            'data' => new SiteMapResource($map),
            'message' => 'Map created.',
        ], 201);
    }

    public function show(Request $request, SiteMap $siteMap): JsonResponse
    {
        if ($siteMap->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Map not found.'], 404);
        }

        $siteMap->load('elements.productRental.product', 'elements.iconType');

        return response()->json([
            'success' => true,
            'data' => new SiteMapResource($siteMap),
            'message' => 'Map retrieved.',
        ]);
    }

    public function update(StoreSiteMapRequest $request, SiteMap $siteMap): JsonResponse
    {
        if ($siteMap->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Map not found.'], 404);
        }

        $siteMap->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new SiteMapResource($siteMap->fresh()),
            'message' => 'Map updated.',
        ]);
    }

    public function destroy(Request $request, SiteMap $siteMap): JsonResponse
    {
        if ($siteMap->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Map not found.'], 404);
        }

        $siteMap->delete();

        return response()->json(['success' => true, 'message' => 'Map deleted.']);
    }

    public function uploadImage(Request $request, SiteMap $siteMap): JsonResponse
    {
        if ($siteMap->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Map not found.'], 404);
        }

        $request->validate(['image' => 'required|image|max:4096']);

        $path = $request->file('image')->store('site-maps', 'public');
        $siteMap->update(['image_url' => Storage::url($path)]);

        return response()->json([
            'success' => true,
            'data' => new SiteMapResource($siteMap->fresh()),
            'message' => 'Map image updated.',
        ]);
    }

    public function deleteImage(Request $request, SiteMap $siteMap): JsonResponse
    {
        if ($siteMap->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Map not found.'], 404);
        }

        $siteMap->update(['image_url' => null]);

        return response()->json([
            'success' => true,
            'data' => new SiteMapResource($siteMap->fresh()),
            'message' => 'Map photo removed.',
        ]);
    }
}
