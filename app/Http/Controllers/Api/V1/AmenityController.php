<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAmenityRequest;
use App\Http\Resources\AmenityResource;
use App\Models\Amenity;
use Illuminate\Http\JsonResponse;

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
}
