<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\GhlProductSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function __construct(
        private GhlProductSyncService $ghlProductSyncService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $categories = Category::where('tenant_id', $request->user()->tenant_id)
            ->withCount('products')
            ->orderBy('sort_order')
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
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category = Category::create($data);

        return response()->json([
            'success' => true,
            'data' => new CategoryResource($category),
            'message' => 'Category created.',
        ], 201);
    }

    public function show(Category $category): JsonResponse
    {
        $category->loadCount('products');

        return response()->json([
            'success' => true,
            'data' => new CategoryResource($category),
            'message' => 'Category retrieved.',
        ]);
    }

    public function update(StoreCategoryRequest $request, Category $category): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['name']) && empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category->update($data);

        return response()->json([
            'success' => true,
            'data' => new CategoryResource($category->fresh()->loadCount('products')),
            'message' => 'Category updated.',
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->products()->detach();
        $category->delete();

        return response()->json(['success' => true, 'message' => 'Category deleted.']);
    }

    public function syncToGhl(Category $category): JsonResponse
    {
        try {
            $category = $this->ghlProductSyncService->syncCategoryToGhl($category);

            return response()->json([
                'success' => true,
                'data' => new CategoryResource($category),
                'message' => 'Category synced to GHL.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function bulkSync(Request $request): JsonResponse
    {
        $results = $this->ghlProductSyncService->bulkSyncCategories($request->user()->tenant_id);

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Synced {$results['synced']} categories, {$results['errors']} errors.",
        ]);
    }
}
