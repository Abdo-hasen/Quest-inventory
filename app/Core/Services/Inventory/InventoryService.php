<?php

declare(strict_types=1);

namespace App\Core\Services\Inventory;

use App\Core\Enums\MovementType;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryService
{
    /**
     * @param  array{product_id: int|string, warehouse_id: int|string, quantity: int|string, reason: string}  $data
     * @return array{inventory: Inventory, movement: InventoryMovement}
     */
    public function adjust(array $data, int $actorId): array
    {
        return DB::transaction(function () use ($data, $actorId): array {
            $productId = (int) $data['product_id'];
            $warehouseId = (int) $data['warehouse_id'];
            $delta = (int) $data['quantity'];

            /** @var Inventory|null $inventory */
            $inventory = Inventory::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($inventory !== null) {
                $newQty = $inventory->quantity_available + $delta;

                if ($newQty < 0) {
                    throw ValidationException::withMessages([
                        'quantity' => __('Adjustment would bring available stock below zero'),
                    ]);
                }

                $inventory->quantity_available = $newQty;
                $inventory->save();
            } else {
                if ($delta < 0) {
                    throw ValidationException::withMessages([
                        'quantity' => __('Adjustment would bring available stock below zero'),
                    ]);
                }

                $inventory = new Inventory;
                $inventory->product_id = $productId;
                $inventory->warehouse_id = $warehouseId;
                $inventory->quantity_available = $delta;
                $inventory->quantity_reserved = 0;
                $inventory->quantity_picked = 0;
                $inventory->quantity_packed = 0;
                $inventory->quantity_shipped = 0;
                $inventory->save();
            }

            $movement = new InventoryMovement;
            $movement->product_id = $productId;
            $movement->warehouse_id = $warehouseId;
            $movement->type = MovementType::Adjustment;
            $movement->quantity_delta = $delta;
            $movement->reason = $data['reason'];
            $movement->actor_id = $actorId;
            $movement->created_at = now();
            $movement->save();

            return [
                'inventory' => $inventory,
                'movement' => $movement,
            ];
        });
    }
}
