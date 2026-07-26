<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Warehouse;

use App\Core\Services\Warehouse\WarehouseService;
use App\Core\Traits\InteractWithResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\StoreWarehouseRequest;
use App\Http\Requests\Warehouse\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use Illuminate\Http\JsonResponse;

final class WarehouseController extends Controller
{
    use InteractWithResponse;

    public function __construct(
        private readonly WarehouseService $warehouseService
    ) {}

    public function index(): JsonResponse
    {
        $warehouses = $this->warehouseService->index();

        return $this->sendSuccessResponse(
            data: WarehouseResource::collection($warehouses)
        );
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $warehouse = $this->warehouseService->store($request->validated());

        return $this->sendSuccessResponse(
            data: new WarehouseResource($warehouse),
            message: __('Warehouse created'),
            code: 201
        );
    }

    public function show(int $id): JsonResponse
    {
        $warehouse = $this->warehouseService->findById($id);

        return $this->sendSuccessResponse(
            data: new WarehouseResource($warehouse)
        );
    }

    public function update(UpdateWarehouseRequest $request, int $id): JsonResponse
    {
        $warehouse = $this->warehouseService->update($request->validated(), $id);

        return $this->sendSuccessResponse(
            data: new WarehouseResource($warehouse),
            message: __('Warehouse updated')
        );
    }
}
