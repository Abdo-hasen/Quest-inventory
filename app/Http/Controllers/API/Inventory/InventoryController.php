<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Inventory;

use App\Core\Services\Inventory\InventoryService;
use App\Core\Traits\InteractWithResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustInventoryRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\InventoryResource;
use Illuminate\Http\JsonResponse;

final class InventoryController extends Controller
{
    use InteractWithResponse;

    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

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
}
