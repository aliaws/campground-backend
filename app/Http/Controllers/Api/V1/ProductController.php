<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductPriceResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductVariationResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'tenant_id' => $request->user()->tenant_id,
        ]);

        $products = $this->productService->list($filters);

        return response()->json([
            'success' => true,
            'data' => ProductResource::collection($products),
            'message' => 'Products retrieved.',
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create(
            $request->validated() + ['tenant_id' => $request->user()->tenant_id]
        );

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product created.',
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'prices', 'variations', 'amenities', 'features']);

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product retrieved.',
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->productService->update($product, $request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product updated.',
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->productService->delete($product);

        return response()->json([
            'success' => true,
            'message' => 'Product deleted.',
        ]);
    }

    public function uploadImage(Request $request, Product $product): JsonResponse
    {
        $request->validate(['image' => 'required|image|max:2048']);

        $product = $this->productService->uploadImage($product, $request->file('image'));

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
            'message' => 'Product image updated.',
        ]);
    }

    public function prices(Product $product): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ProductPriceResource::collection($product->prices),
            'message' => 'Product prices retrieved.',
        ]);
    }

    public function storePrice(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'label' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ]);

        $price = $this->productService->addPrice($product, $request->all());

        return response()->json([
            'success' => true,
            'data' => new ProductPriceResource($price),
            'message' => 'Product price added.',
        ], 201);
    }

    public function variations(Product $product): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ProductVariationResource::collection($product->variations),
            'message' => 'Product variations retrieved.',
        ]);
    }

    public function storeVariation(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:255',
            'price_modifier' => 'required|numeric',
        ]);

        $variation = $this->productService->addVariation($product, $request->all());

        return response()->json([
            'success' => true,
            'data' => new ProductVariationResource($variation),
            'message' => 'Product variation added.',
        ], 201);
    }
}
