<?php

declare(strict_types=1);

namespace App\Core\Services\Shipment;

use App\Core\Enums\MovementType;
use App\Core\Enums\ReservationStatus;
use App\Core\Enums\ShipmentAttemptStatus;
use App\Core\Enums\ShipmentStatus;
use App\Core\Helpers\Shipping\ShippingProviderInterface;
use App\Jobs\CheckShipmentStatusJob;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Reservation;
use App\Models\ReservationHistory;
use App\Models\Shipment;
use App\Models\ShipmentAttempt;
use Illuminate\Support\Facades\DB;

final class ShipmentService
{
    public function __construct(
        private readonly ShippingProviderInterface $shippingProvider
    ) {}

    public function processShipment(Shipment $shipment): void
    {
        DB::transaction(function () use ($shipment): void {
            /** @var Shipment $lockedShipment */
            $lockedShipment = Shipment::query()
                ->where('id', $shipment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedShipment->status === ShipmentStatus::Shipped || $lockedShipment->status === ShipmentStatus::Failed) {
                return;
            }

            $lockedShipment->status = ShipmentStatus::InTransit;
            $lockedShipment->save();

            $result = $this->shippingProvider->ship($lockedShipment);

            match ($result['status']) {
                ShipmentAttemptStatus::Success => $this->confirmShipmentInternal($lockedShipment, $result['quantity_shipped'], $result['raw']),
                ShipmentAttemptStatus::PermanentFailure => $this->markFailedInternal($lockedShipment, 'Permanent failure from provider', $result['raw']),
                ShipmentAttemptStatus::Timeout => $this->handleTimeoutInternal($lockedShipment, $result['raw']),
                ShipmentAttemptStatus::DelayedSuccess => $this->handleDelayedSuccessInternal($lockedShipment, $result['raw']),
            };
        });
    }

    public function confirmShipment(Shipment $shipment, int $qty): void
    {
        DB::transaction(function () use ($shipment, $qty): void {
            /** @var Shipment $lockedShipment */
            $lockedShipment = Shipment::query()
                ->where('id', $shipment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->confirmShipmentInternal($lockedShipment, $qty, ['source' => 'webhook']);
        });
    }

    public function markFailed(Shipment $shipment, ?string $reason = null): void
    {
        DB::transaction(function () use ($shipment, $reason): void {
            /** @var Shipment $lockedShipment */
            $lockedShipment = Shipment::query()
                ->where('id', $shipment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedShipment->status === ShipmentStatus::Shipped) {
                return;
            }

            $this->markFailedInternal($lockedShipment, $reason ?? 'Shipment failed');
        });
    }

    public function handleTimeout(Shipment $shipment, ?array $response = null): void
    {
        DB::transaction(function () use ($shipment, $response): void {
            /** @var Shipment $lockedShipment */
            $lockedShipment = Shipment::query()
                ->where('id', $shipment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedShipment->status === ShipmentStatus::Shipped || $lockedShipment->status === ShipmentStatus::Failed) {
                return;
            }

            $this->handleTimeoutInternal($lockedShipment, $response);
        });
    }

    private function confirmShipmentInternal(Shipment $shipment, int $qty, ?array $response = null): void
    {
        // Guard against late confirmation / double deduction
        if ($shipment->status === ShipmentStatus::Shipped) {
            return;
        }

        /** @var Reservation $reservation */
        $reservation = Reservation::query()
            ->where('id', $shipment->reservation_id)
            ->lockForUpdate()
            ->firstOrFail();

        /** @var Inventory $inventory */
        $inventory = Inventory::query()
            ->where('product_id', $reservation->product_id)
            ->where('warehouse_id', $reservation->warehouse_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($qty > $inventory->quantity_packed) {
            throw new \DomainException("Quantity shipped ({$qty}) exceeds available packed quantity ({$inventory->quantity_packed}).");
        }

        $inventory->quantity_packed -= $qty;
        $inventory->quantity_shipped += $qty;
        $inventory->save();

        $fromStatus = $reservation->status;
        $reservation->quantity_shipped += $qty;

        if ($reservation->quantity_shipped >= $reservation->quantity) {
            $newReservationStatus = ReservationStatus::Fulfilled;
        } else {
            $newReservationStatus = ReservationStatus::PartiallyFulfilled;
        }

        $reservation->status = $newReservationStatus;
        $reservation->save();

        $reservation->loadMissing('orderLine');

        $movement = new InventoryMovement;
        $movement->product_id = $reservation->product_id;
        $movement->warehouse_id = $reservation->warehouse_id;
        $movement->type = MovementType::Ship;
        $movement->quantity_delta = $qty;
        $movement->reason = 'Stock shipped';
        $movement->actor_id = null;
        $movement->related_order_id = $reservation->orderLine?->sales_order_id;
        $movement->related_reservation_id = $reservation->id;
        $movement->created_at = now();
        $movement->save();

        $history = new ReservationHistory;
        $history->reservation_id = $reservation->id;
        $history->from_status = $fromStatus;
        $history->to_status = $newReservationStatus;
        $history->quantity_affected = $qty;
        $history->actor_id = null;
        $history->notes = 'Stock shipped';
        $history->created_at = now();
        $history->save();

        $shipment->status = ShipmentStatus::Shipped;
        $shipment->save();

        $attemptNumber = ShipmentAttempt::query()->where('shipment_id', $shipment->id)->count() + 1;

        $attempt = new ShipmentAttempt;
        $attempt->shipment_id = $shipment->id;
        $attempt->attempt_number = $attemptNumber;
        $attempt->status = ShipmentAttemptStatus::Success;
        $attempt->provider_response = $response ?? ['quantity_shipped' => $qty];
        $attempt->created_at = now();
        $attempt->save();
    }

    private function markFailedInternal(Shipment $shipment, string $reason, ?array $response = null): void
    {
        if ($shipment->status === ShipmentStatus::Failed) {
            return;
        }

        $shipment->status = ShipmentStatus::Failed;
        $shipment->save();

        $attemptNumber = ShipmentAttempt::query()->where('shipment_id', $shipment->id)->count() + 1;

        $attempt = new ShipmentAttempt;
        $attempt->shipment_id = $shipment->id;
        $attempt->attempt_number = $attemptNumber;
        $attempt->status = ShipmentAttemptStatus::PermanentFailure;
        $attempt->provider_response = $response ?? ['reason' => $reason];
        $attempt->created_at = now();
        $attempt->save();
    }

    private function handleTimeoutInternal(Shipment $shipment, ?array $response = null): void
    {
        $shipment->status = ShipmentStatus::Timeout;
        $shipment->save();

        $attemptNumber = ShipmentAttempt::query()->where('shipment_id', $shipment->id)->count() + 1;

        $attempt = new ShipmentAttempt;
        $attempt->shipment_id = $shipment->id;
        $attempt->attempt_number = $attemptNumber;
        $attempt->status = ShipmentAttemptStatus::Timeout;
        $attempt->provider_response = $response;
        $attempt->created_at = now();
        $attempt->save();

        CheckShipmentStatusJob::dispatch($shipment)->delay(now()->addSeconds(60));
    }

    private function handleDelayedSuccessInternal(Shipment $shipment, ?array $response = null): void
    {
        $shipment->status = ShipmentStatus::InTransit;
        $shipment->save();

        $attemptNumber = ShipmentAttempt::query()->where('shipment_id', $shipment->id)->count() + 1;

        $attempt = new ShipmentAttempt;
        $attempt->shipment_id = $shipment->id;
        $attempt->attempt_number = $attemptNumber;
        $attempt->status = ShipmentAttemptStatus::DelayedSuccess;
        $attempt->provider_response = $response;
        $attempt->created_at = now();
        $attempt->save();

        CheckShipmentStatusJob::dispatch($shipment)->delay(now()->addSeconds(60));
    }
}
