<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAmenityRequest;
use App\Http\Resources\AmenityResource;
use App\Models\Amenity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AmenityController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => AmenityResource::collection(Amenity::orderBy('name')->get()),
            'message' => 'Amenities retrieved.',
        ]);
    }

    public function store(StoreAmenityRequest $request): JsonResponse
    {
        $amenity = Amenity::create($request->validated());

        return response()->json([
            'success' => true,
            'data' => new AmenityResource($amenity),
            'message' => 'Amenity created.',
        ], 201);
    }

    public function update(Request $request, Amenity $amenity): JsonResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $amenity->update($request->only(['name', 'icon', 'is_active']));

        return response()->json([
            'success' => true,
            'data' => new AmenityResource($amenity->fresh()),
            'message' => 'Amenity updated.',
        ]);
    }

    public function destroy(Amenity $amenity): JsonResponse
    {
        $amenity->products()->detach();
        $amenity->delete();

        return response()->json(['success' => true, 'message' => 'Amenity deleted.']);
    }
}
