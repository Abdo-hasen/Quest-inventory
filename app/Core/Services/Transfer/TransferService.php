<?php

declare(strict_types=1);

namespace App\Core\Services\Transfer;

use App\Core\Enums\MovementType;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransferService
{
    /**
     * Transfer available stock between warehouses atomically.
     *
     * @param  array{product_id: int, from_warehouse_id: int, to_warehouse_id: int, quantity: int}  $data
     * @return array{product_id: int, from_warehouse_id: int, to_warehouse_id: int, quantity: int, movement_ids: list<int>}
     */
    public function transfer(array $data, int $actorId): array
    {
        return DB::transaction(function () use ($data, $actorId): array {
            $productId = (int) $data['product_id'];
            $fromWarehouseId = (int) $data['from_warehouse_id'];
            $toWarehouseId = (int) $data['to_warehouse_id'];
            $quantity = (int) $data['quantity'];

            $fromInventory = Inventory::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $fromWarehouseId)
                ->firstOrFail();

            $toInventory = Inventory::query()->firstOrCreate(
                [
                    'product_id' => $productId,
                    'warehouse_id' => $toWarehouseId,
                ],
                [
                    'quantity_available' => 0,
                    'quantity_reserved' => 0,
                    'quantity_picked' => 0,
                    'quantity_packed' => 0,
                    'quantity_shipped' => 0,
                ]
            );

            // Lock both inventory rows in ascending ID order to prevent MySQL deadlocks under high concurrency.
            $ids = collect([$fromInventory->id, $toInventory->id])->sort()->values()->toArray();

            $lockedInventories = Inventory::query()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            /** @var Inventory $fromLocked */
            $fromLocked = $lockedInventories->firstWhere('id', $fromInventory->id);

            /** @var Inventory $toLocked */
            $toLocked = $lockedInventories->firstWhere('id', $toInventory->id);

            if ($fromLocked->quantity_available < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => __('Insufficient available stock for transfer'),
                ]);
            }

            $fromLocked->quantity_available -= $quantity;
            $toLocked->quantity_available += $quantity;

            $fromLocked->save();
            $toLocked->save();

            $now = now();

            /** @var InventoryMovement $outMovement */
            $outMovement = InventoryMovement::query()->create([
                'product_id' => $productId,
                'warehouse_id' => $fromWarehouseId,
                'type' => MovementType::TransferOut,
                'quantity_delta' => -$quantity,
                'actor_id' => $actorId,
                'created_at' => $now,
            ]);

            /** @var InventoryMovement $inMovement */
            $inMovement = InventoryMovement::query()->create([
                'product_id' => $productId,
                'warehouse_id' => $toWarehouseId,
                'type' => MovementType::TransferIn,
                'quantity_delta' => $quantity,
                'actor_id' => $actorId,
                'created_at' => $now,
            ]);

            return [
                'product_id' => $productId,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'quantity' => $quantity,
                'movement_ids' => [$outMovement->id, $inMovement->id],
            ];
        });
    }
}
