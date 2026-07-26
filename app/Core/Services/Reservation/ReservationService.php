<?php

declare(strict_types=1);

namespace App\Core\Services\Reservation;

use App\Core\Enums\MovementType;
use App\Core\Enums\ReservationStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\OrderLine;
use App\Models\Reservation;
use App\Models\ReservationHistory;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReservationService
{
    public function release(int $reservationId, ?int $actorId): Reservation
    {
        return DB::transaction(function () use ($reservationId, $actorId): Reservation {
            /** @var Reservation $reservation */
            $reservation = Reservation::query()
                ->where('id', $reservationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->status !== ReservationStatus::Open) {
                throw new DomainException(__('Reservation cannot be released in its current state'));
            }

            /** @var Inventory $inventory */
            $inventory = Inventory::query()
                ->where('product_id', $reservation->product_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantityToRelease = $reservation->quantity;

            $inventory->quantity_reserved -= $quantityToRelease;
            $inventory->quantity_available += $quantityToRelease;
            $inventory->save();

            $fromStatus = $reservation->status;
            $reservation->status = ReservationStatus::Released;
            $reservation->quantity_released += $quantityToRelease;
            $reservation->save();

            $reservation->loadMissing('orderLine');

            $movement = new InventoryMovement;
            $movement->product_id = $reservation->product_id;
            $movement->warehouse_id = $reservation->warehouse_id;
            $movement->type = MovementType::Release;
            $movement->quantity_delta = -$quantityToRelease;
            $movement->reason = 'Reservation release';
            $movement->actor_id = $actorId;
            $movement->related_order_id = $reservation->orderLine?->sales_order_id;
            $movement->related_reservation_id = $reservation->id;
            $movement->created_at = now();
            $movement->save();

            $history = new ReservationHistory;
            $history->reservation_id = $reservation->id;
            $history->from_status = $fromStatus;
            $history->to_status = ReservationStatus::Released;
            $history->quantity_affected = $quantityToRelease;
            $history->actor_id = $actorId;
            $history->notes = 'Reservation released';
            $history->created_at = now();
            $history->save();

            return $reservation;
        });
    }

    public function expire(int $reservationId): bool
    {
        return DB::transaction(function () use ($reservationId): bool {
            /** @var Reservation|null $reservation */
            $reservation = Reservation::query()
                ->where('id', $reservationId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null || $reservation->status !== ReservationStatus::Open || $reservation->expires_at >= now()) {
                return false;
            }

            /** @var Inventory $inventory */
            $inventory = Inventory::query()
                ->where('product_id', $reservation->product_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantityToExpire = $reservation->quantity;

            if ($quantityToExpire > 0) {
                $inventory->quantity_reserved -= $quantityToExpire;
                $inventory->quantity_available += $quantityToExpire;
                $inventory->save();
            }

            $fromStatus = $reservation->status;
            $reservation->status = ReservationStatus::Expired;
            $reservation->quantity_released += $quantityToExpire;
            $reservation->save();

            $reservation->loadMissing('orderLine');

            $movement = new InventoryMovement;
            $movement->product_id = $reservation->product_id;
            $movement->warehouse_id = $reservation->warehouse_id;
            $movement->type = MovementType::Release;
            $movement->quantity_delta = -$quantityToExpire;
            $movement->reason = 'Reservation expired';
            $movement->actor_id = null;
            $movement->related_order_id = $reservation->orderLine?->sales_order_id;
            $movement->related_reservation_id = $reservation->id;
            $movement->created_at = now();
            $movement->save();

            $history = new ReservationHistory;
            $history->reservation_id = $reservation->id;
            $history->from_status = $fromStatus;
            $history->to_status = ReservationStatus::Expired;
            $history->quantity_affected = $quantityToExpire;
            $history->actor_id = null;
            $history->notes = 'Reservation expired by scheduler';
            $history->created_at = now();
            $history->save();

            return true;
        });
    }

    public function partialCancel(int $orderId, int $lineId, int $newQty, ?int $actorId): OrderLine
    {
        /** @var OrderLine $orderLine */
        $orderLine = OrderLine::query()
            ->where('id', $lineId)
            ->where('sales_order_id', $orderId)
            ->with(['reservation'])
            ->firstOrFail();

        $reservation = $orderLine->reservation;

        if ($reservation === null) {
            throw new DomainException(__('Reservation not found for order line'));
        }

        return DB::transaction(function () use ($orderLine, $reservation, $newQty, $actorId): OrderLine {
            /** @var Reservation $lockedReservation */
            $lockedReservation = Reservation::query()
                ->where('id', $reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReservation->status !== ReservationStatus::Open) {
                throw new DomainException(__('Reservation cannot be updated in its current state'));
            }

            $consumed = $lockedReservation->quantity_picked + $lockedReservation->quantity_packed + $lockedReservation->quantity_shipped;

            if ($newQty < $consumed) {
                throw ValidationException::withMessages([
                    'quantity' => __('New quantity is below already-consumed amount'),
                ]);
            }

            if ($newQty > $lockedReservation->quantity) {
                throw ValidationException::withMessages([
                    'quantity' => __('Cannot increase reservation quantity'),
                ]);
            }

            if ($newQty === $lockedReservation->quantity) {
                return $orderLine;
            }

            $delta = $lockedReservation->quantity - $newQty;

            /** @var Inventory $inventory */
            $inventory = Inventory::query()
                ->where('product_id', $lockedReservation->product_id)
                ->where('warehouse_id', $lockedReservation->warehouse_id)
                ->lockForUpdate()
                ->firstOrFail();

            $inventory->quantity_reserved -= $delta;
            $inventory->quantity_available += $delta;
            $inventory->save();

            $fromStatus = $lockedReservation->status;
            $lockedReservation->quantity = $newQty;
            $lockedReservation->quantity_released += $delta;

            if ($newQty === 0) {
                $lockedReservation->status = ReservationStatus::Released;
            }

            $lockedReservation->save();

            $orderLine->quantity = $newQty;
            $orderLine->save();

            $movement = new InventoryMovement;
            $movement->product_id = $lockedReservation->product_id;
            $movement->warehouse_id = $lockedReservation->warehouse_id;
            $movement->type = MovementType::Release;
            $movement->quantity_delta = -$delta;
            $movement->reason = 'Partial cancellation';
            $movement->actor_id = $actorId;
            $movement->related_order_id = $orderLine->sales_order_id;
            $movement->related_reservation_id = $lockedReservation->id;
            $movement->created_at = now();
            $movement->save();

            $history = new ReservationHistory;
            $history->reservation_id = $lockedReservation->id;
            $history->from_status = $fromStatus;
            $history->to_status = $lockedReservation->status;
            $history->quantity_affected = $delta;
            $history->actor_id = $actorId;
            $history->notes = 'Partial cancellation';
            $history->created_at = now();
            $history->save();

            return $orderLine->fresh(['reservation']);
        });
    }
}
