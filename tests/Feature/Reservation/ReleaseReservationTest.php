<?php

declare(strict_types=1);

use App\Core\Enums\MovementType;
use App\Core\Enums\ReservationStatus;
use App\Core\Enums\UserRole;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ReservationHistory;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->headers = [
        'Accept' => 'application/json',
        'X-API-KEY' => 'secret_app_key_123',
    ];

    $this->warehouseOperator = User::factory()->create(['role' => UserRole::WarehouseOperator]);
    $this->orderCreator = User::factory()->create(['role' => UserRole::OrderCreator]);

    $this->warehouse = Warehouse::factory()->create(['is_active' => true]);
    $this->product = Product::factory()->create();

    $this->inventory = Inventory::factory()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 10,
        'quantity_reserved' => 5,
    ]);

    $this->order = SalesOrder::factory()->create(['user_id' => $this->orderCreator->id]);

    $this->orderLine = OrderLine::factory()->create([
        'sales_order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 5,
    ]);

    $this->reservation = Reservation::factory()->create([
        'order_line_id' => $this->orderLine->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 5,
        'status' => ReservationStatus::Open,
        'expires_at' => now()->addMinutes(30),
    ]);
});

test('warehouse operator can release open reservation', function () {
    $response = $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/release", [], $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Reservation released',
            'data' => [
                'reservation_id' => $this->reservation->id,
                'status' => 'released',
            ],
        ]);

    $this->reservation->refresh();
    expect($this->reservation->status)->toBe(ReservationStatus::Released)
        ->and($this->reservation->quantity_released)->toBe(5)
        ->and($this->reservation->quantity)->toBe(5);

    $this->inventory->refresh();
    expect($this->inventory->quantity_reserved)->toBe(0)
        ->and($this->inventory->quantity_available)->toBe(15);

    $movement = InventoryMovement::where('related_reservation_id', $this->reservation->id)
        ->where('type', MovementType::Release->value)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->quantity_delta)->toBe(-5)
        ->and($movement->actor_id)->toBe($this->warehouseOperator->id);

    $history = ReservationHistory::where('reservation_id', $this->reservation->id)->first();
    expect($history)->not->toBeNull()
        ->and($history->from_status)->toBe(ReservationStatus::Open)
        ->and($history->to_status)->toBe(ReservationStatus::Released)
        ->and($history->quantity_affected)->toBe(5)
        ->and($history->actor_id)->toBe($this->warehouseOperator->id);
});

test('releasing an already released reservation returns 409', function () {
    $this->reservation->update(['status' => ReservationStatus::Released]);

    $response = $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/release", [], $this->headers);

    $response->assertStatus(409)
        ->assertJson([
            'ok' => false,
            'code' => 409,
            'message' => 'Reservation cannot be released in its current state',
        ]);

    $this->inventory->refresh();
    expect($this->inventory->quantity_reserved)->toBe(5)
        ->and($this->inventory->quantity_available)->toBe(10);

    expect(InventoryMovement::where('type', MovementType::Release->value)->count())->toBe(0);
});

test('releasing an expired reservation returns 409', function () {
    $this->reservation->update(['status' => ReservationStatus::Expired]);

    $response = $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/release", [], $this->headers);

    $response->assertStatus(409)
        ->assertJson([
            'ok' => false,
            'code' => 409,
        ]);
});

test('releasing a picked reservation returns 409', function () {
    $this->reservation->update(['status' => ReservationStatus::Picked]);

    $response = $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/release", [], $this->headers);

    $response->assertStatus(409)
        ->assertJson([
            'ok' => false,
            'code' => 409,
        ]);
});

test('non-warehouse operator cannot release reservation', function () {
    $response = $this->actingAs($this->orderCreator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/release", [], $this->headers);

    $response->assertStatus(403);
});
