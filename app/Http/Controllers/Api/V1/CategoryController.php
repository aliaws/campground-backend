<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = Category::where('tenant_id', $request->user()->tenant_id)
            ->with('children')
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => CategoryResource::collection($categories),
            'message' => 'Categories retrieved.',
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json([
            'success' => true,
            'data' => new CategoryResource($category),
            'message' => 'Category created.',
        ], 201);
    }
}
