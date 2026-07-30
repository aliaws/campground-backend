<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteMapIconTypeRequest;
use App\Http\Resources\SiteMapIconTypeResource;
use App\Models\SiteMapIconType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SiteMapIconTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $iconTypes = SiteMapIconType::where('engage_organization_location_id', $request->user()->resolveOrganizationLocationId())
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => SiteMapIconTypeResource::collection($iconTypes),
            'message' => 'Icon types retrieved.',
        ]);
    }

    public function store(StoreSiteMapIconTypeRequest $request): JsonResponse
    {
        $path = $request->file('image')->store('site-map-icons', 'public');

        $iconType = SiteMapIconType::create([
            'name' => $request->validated('name'),
            'image_url' => Storage::url($path),
            'engage_organization_location_id' => $request->user()->resolveOrganizationLocationId(),
        ]);

        return response()->json([
            'success' => true,
            'data' => new SiteMapIconTypeResource($iconType),
            'message' => 'Icon added.',
        ], 201);
    }

    public function destroy(Request $request, SiteMapIconType $iconType): JsonResponse
    {
        if ($iconType->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Icon not found.'], 404);
        }

        $iconType->delete();

        return response()->json(['success' => true, 'message' => 'Icon removed.']);
    }
}
