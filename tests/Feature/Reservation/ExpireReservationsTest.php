<?php

declare(strict_types=1);

use App\Core\Enums\MovementType;
use App\Core\Enums\ReservationStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ReservationHistory;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::factory()->create(['is_active' => true]);
    $this->product = Product::factory()->create();

    $this->inventory = Inventory::factory()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 10,
        'quantity_reserved' => 10,
    ]);

    $this->order = SalesOrder::factory()->create();

    $this->staleOrderLine = OrderLine::factory()->create([
        'sales_order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 6,
    ]);

    $this->staleReservation = Reservation::factory()->create([
        'order_line_id' => $this->staleOrderLine->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 6,
        'status' => ReservationStatus::Open,
        'expires_at' => now()->subMinute(),
    ]);

    $this->activeOrderLine = OrderLine::factory()->create([
        'sales_order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 4,
    ]);

    $this->activeReservation = Reservation::factory()->create([
        'order_line_id' => $this->activeOrderLine->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 4,
        'status' => ReservationStatus::Open,
        'expires_at' => now()->addMinutes(30),
    ]);
});

test('expire command releases stale open reservations', function () {
    $this->artisan('reservations:expire')
        ->assertExitCode(0);

    $this->staleReservation->refresh();
    expect($this->staleReservation->status)->toBe(ReservationStatus::Expired)
        ->and($this->staleReservation->quantity_released)->toBe(6);

    $this->activeReservation->refresh();
    expect($this->activeReservation->status)->toBe(ReservationStatus::Open);

    $this->inventory->refresh();
    expect($this->inventory->quantity_reserved)->toBe(4)
        ->and($this->inventory->quantity_available)->toBe(16);

    $movement = InventoryMovement::where('related_reservation_id', $this->staleReservation->id)->first();
    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe(MovementType::Release)
        ->and($movement->quantity_delta)->toBe(-6)
        ->and($movement->actor_id)->toBeNull();

    $history = ReservationHistory::where('reservation_id', $this->staleReservation->id)->first();
    expect($history)->not->toBeNull()
        ->and($history->from_status)->toBe(ReservationStatus::Open)
        ->and($history->to_status)->toBe(ReservationStatus::Expired)
        ->and($history->quantity_affected)->toBe(6)
        ->and($history->actor_id)->toBeNull();
});

test('expire command is idempotent when run twice', function () {
    $this->artisan('reservations:expire')->assertExitCode(0);
    $this->artisan('reservations:expire')->assertExitCode(0);

    $this->inventory->refresh();
    expect($this->inventory->quantity_reserved)->toBe(4)
        ->and($this->inventory->quantity_available)->toBe(16);

    expect(InventoryMovement::where('related_reservation_id', $this->staleReservation->id)->count())->toBe(1);
    expect(ReservationHistory::where('reservation_id', $this->staleReservation->id)->count())->toBe(1);
});
