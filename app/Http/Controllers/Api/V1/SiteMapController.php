<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteMapRequest;
use App\Http\Resources\SiteMapResource;
use App\Models\SiteMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $tenantId = $request->user()->tenant_id;
        $data = $request->validated() + ['tenant_id' => $tenantId];

        $map = DB::transaction(function () use ($data, $tenantId) {
            // Only one map per tenant can be the default guests see — unset
            // any existing default before creating this one as the new default.
            if (! empty($data['is_default'])) {
                SiteMap::where('tenant_id', $tenantId)->where('is_default', true)->update(['is_default' => false]);
            }

            return SiteMap::create($data);
        });

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

        $data = $request->validated();

        DB::transaction(function () use ($data, $siteMap) {
            // Enforce a single default per tenant — marking this map as
            // default unsets it on every sibling first, so guests always see
            // exactly one map (never zero, never more than one).
            if (! empty($data['is_default'])) {
                SiteMap::where('tenant_id', $siteMap->tenant_id)
                    ->where('id', '!=', $siteMap->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $siteMap->update($data);
        });

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

        // A placed marker's x/y is just a percentage of the canvas box, with
        // no inherent tie to what's actually drawn in the photo underneath
        // it — swapping in a different photo (first upload or a replace)
        // would leave every existing marker sitting on a now-meaningless
        // spot of the new image. Wrapped in a transaction so the map never
        // ends up with a new photo but stale markers (or vice versa) if
        // anything fails partway through.
        DB::transaction(function () use ($siteMap, $path) {
            $siteMap->elements()->delete();
            $siteMap->update(['image_url' => Storage::url($path)]);
        });

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

        // Same reasoning as uploadImage(): once the photo is gone, every
        // marker's position is meaningless, so clear them together with it.
        DB::transaction(function () use ($siteMap) {
            $siteMap->elements()->delete();
            $siteMap->update(['image_url' => null]);
        });

        return response()->json([
            'success' => true,
            'data' => new SiteMapResource($siteMap->fresh()),
            'message' => 'Map photo and markers removed.',
        ]);
    }
}
