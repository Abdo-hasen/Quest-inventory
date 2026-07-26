<?php

declare(strict_types=1);

namespace App\Core\Services\Order;

use App\Core\Enums\MovementType;
use App\Core\Enums\OrderStatus;
use App\Core\Enums\ReservationStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\OrderLine;
use App\Models\Reservation;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderService
{
    /**
     * @param  array{lines: list<array{product_id: int|string, warehouse_id: int|string, quantity: int|string}>}  $data
     */
    public function create(array $data, int $actorId): SalesOrder
    {
        return DB::transaction(function () use ($data, $actorId): SalesOrder {
            $inventories = [];

            foreach ($data['lines'] as $index => $line) {
                $productId = (int) $line['product_id'];
                $warehouseId = (int) $line['warehouse_id'];
                $quantity = (int) $line['quantity'];

                /** @var Inventory|null $inventory */
                $inventory = Inventory::query()
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();

                if ($inventory === null || $inventory->quantity_available < $quantity) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => __('Insufficient stock'),
                    ]);
                }

                $inventories[$index] = $inventory;
            }

            $order = new SalesOrder;
            $order->user_id = $actorId;
            $order->status = OrderStatus::Open;
            $order->save();

            $ttlMinutes = (int) config('reservations.ttl_minutes', 30);
            $expiresAt = now()->addMinutes($ttlMinutes);

            foreach ($data['lines'] as $index => $line) {
                $productId = (int) $line['product_id'];
                $warehouseId = (int) $line['warehouse_id'];
                $quantity = (int) $line['quantity'];

                $inventory = $inventories[$index];
                $inventory->quantity_available -= $quantity;
                $inventory->quantity_reserved += $quantity;
                $inventory->save();

                $orderLine = new OrderLine;
                $orderLine->sales_order_id = $order->id;
                $orderLine->product_id = $productId;
                $orderLine->warehouse_id = $warehouseId;
                $orderLine->quantity = $quantity;
                $orderLine->save();

                $reservation = new Reservation;
                $reservation->order_line_id = $orderLine->id;
                $reservation->product_id = $productId;
                $reservation->warehouse_id = $warehouseId;
                $reservation->quantity = $quantity;
                $reservation->quantity_picked = 0;
                $reservation->quantity_packed = 0;
                $reservation->quantity_shipped = 0;
                $reservation->quantity_released = 0;
                $reservation->status = ReservationStatus::Open;
                $reservation->expires_at = $expiresAt;
                $reservation->save();

                $movement = new InventoryMovement;
                $movement->product_id = $productId;
                $movement->warehouse_id = $warehouseId;
                $movement->type = MovementType::Reserve;
                $movement->quantity_delta = $quantity;
                $movement->reason = 'Order reservation';
                $movement->actor_id = $actorId;
                $movement->related_order_id = $order->id;
                $movement->related_reservation_id = $reservation->id;
                $movement->created_at = now();
                $movement->save();
            }

            return $order->load(['orderLines.reservation']);
        });
    }
}
