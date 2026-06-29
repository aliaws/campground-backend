<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeatureRequest;
use App\Http\Resources\FeatureResource;
use App\Models\Feature;
use Illuminate\Http\JsonResponse;

class FeatureController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => FeatureResource::collection(Feature::orderBy('name')->get()),
            'message' => 'Features retrieved.',
        ]);
    }

    public function store(StoreFeatureRequest $request): JsonResponse
    {
        $feature = Feature::create($request->validated());

        return response()->json([
            'success' => true,
            'data' => new FeatureResource($feature),
            'message' => 'Feature created.',
        ], 201);
    }
}
