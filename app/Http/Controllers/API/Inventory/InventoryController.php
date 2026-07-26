<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Inventory;

use App\Core\Services\Inventory\InventoryService;
use App\Core\Services\Transfer\TransferService;
use App\Core\Traits\InteractWithResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustInventoryRequest;
use App\Http\Requests\Inventory\TransferRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventoryController extends Controller
{
    use InteractWithResponse;

    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly TransferService $transferService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        $productId = (int) $request->query('product_id');
        $warehouseId = $request->query('warehouse_id') !== null ? (int) $request->query('warehouse_id') : null;

        if ($warehouseId !== null) {
            $inventory = Inventory::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->first();

            if ($inventory === null) {
                return $this->sendSuccessResponse(data: [
                    'id' => null,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity_available' => 0,
                    'quantity_reserved' => 0,
                    'quantity_picked' => 0,
                    'quantity_packed' => 0,
                    'quantity_shipped' => 0,
                ]);
            }

            return $this->sendSuccessResponse(data: new InventoryResource($inventory));
        }

        $inventories = Inventory::query()
            ->where('product_id', $productId)
            ->get();

        return $this->sendSuccessResponse(data: InventoryResource::collection($inventories));
    }

    public function movements(Request $request, int $product): JsonResponse
    {
        $warehouseId = $request->query('warehouse_id') !== null ? (int) $request->query('warehouse_id') : null;
        $perPage = min((int) ($request->query('per_page') ?? 20), 100);

        $paginator = InventoryMovement::query()
            ->where('product_id', $product)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->with(['actor'])
            ->paginate($perPage);

        return $this->sendPaginatedResponse(InventoryMovementResource::collection($paginator));
    }

    public function adjust(AdjustInventoryRequest $request): JsonResponse
    {
        $result = $this->inventoryService->adjust(
            $request->validated(),
            (int) $request->user()->id
        );

        return $this->sendSuccessResponse(
            data: [
                'inventory' => new InventoryResource($result['inventory']),
                'movement' => new InventoryMovementResource($result['movement']),
            ],
            message: __('Stock adjusted')
        );
    }

    public function transfer(TransferRequest $request): JsonResponse
    {
        $result = $this->transferService->transfer(
            $request->validated(),
            (int) $request->user()->id
        );

        return $this->sendSuccessResponse(
            data: $result,
            message: __('Stock transferred')
        );
    }
}
