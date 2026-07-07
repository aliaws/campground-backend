<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;

class PublicCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::where('tenant_id', TenantResolver::resolveDefault())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => CategoryResource::collection($categories),
            'message' => 'Categories retrieved.',
        ]);
    }
}
