<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeatureRequest;
use App\Http\Resources\FeatureResource;
use App\Models\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function update(Request $request, Feature $feature): JsonResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $feature->update($request->only(['name', 'icon', 'is_active']));

        return response()->json([
            'success' => true,
            'data' => new FeatureResource($feature->fresh()),
            'message' => 'Feature updated.',
        ]);
    }

    public function destroy(Feature $feature): JsonResponse
    {
        $feature->products()->detach();
        $feature->delete();

        return response()->json(['success' => true, 'message' => 'Feature deleted.']);
    }
}
