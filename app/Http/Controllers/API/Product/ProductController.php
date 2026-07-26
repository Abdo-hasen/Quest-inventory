<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Product;

use App\Core\Services\Product\ProductService;
use App\Core\Traits\InteractWithResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

final class ProductController extends Controller
{
    use InteractWithResponse;

    public function __construct(
        private readonly ProductService $productService
    ) {}

    public function index(): JsonResponse
    {
        $products = $this->productService->index();
        $products->through(fn (Product $product): array => (new ProductResource($product))->resolve());

        return $this->sendPaginatedResponse(
            resource: $products
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->store($request->validated());

        return $this->sendSuccessResponse(
            data: new ProductResource($product),
            message: __('Product created'),
            code: 201
        );
    }

    public function show(int $id): JsonResponse
    {
        $product = $this->productService->findById($id);

        return $this->sendSuccessResponse(
            data: new ProductResource($product)
        );
    }

    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $product = $this->productService->update($request->validated(), $id);

        return $this->sendSuccessResponse(
            data: new ProductResource($product),
            message: __('Product updated')
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->productService->destroy($id);

        return $this->sendSuccessResponse(
            message: __('Product deleted')
        );
    }
}
