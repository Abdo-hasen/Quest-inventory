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
        'quantity_reserved' => 0,
        'quantity_picked' => 5,
        'quantity_packed' => 0,
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
        'quantity_picked' => 5,
        'quantity_packed' => 0,
        'status' => ReservationStatus::Picked,
        'expires_at' => now()->addMinutes(30),
    ]);
});

test('warehouse operator can fully pack picked reservation', function () {
    $response = $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/pack", ['quantity' => 5], $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Stock marked as packed',
            'data' => [
                'reservation_id' => $this->reservation->id,
                'quantity_packed' => 5,
                'quantity_picked' => 0,
                'status' => 'packed',
            ],
        ]);

    $this->reservation->refresh();
    expect($this->reservation->status)->toBe(ReservationStatus::Packed)
        ->and($this->reservation->quantity_packed)->toBe(5);

    $this->inventory->refresh();
    expect($this->inventory->quantity_picked)->toBe(0)
        ->and($this->inventory->quantity_packed)->toBe(5);

    $movement = InventoryMovement::where('related_reservation_id', $this->reservation->id)
        ->where('type', MovementType::Pack->value)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->quantity_delta)->toBe(5)
        ->and($movement->actor_id)->toBe($this->warehouseOperator->id);

    $history = ReservationHistory::where('reservation_id', $this->reservation->id)->first();
    expect($history)->not->toBeNull()
        ->and($history->from_status)->toBe(ReservationStatus::Picked)
        ->and($history->to_status)->toBe(ReservationStatus::Packed)
        ->and($history->quantity_affected)->toBe(5)
        ->and($history->actor_id)->toBe($this->warehouseOperator->id);
});

test('warehouse operator can partially pack picked reservation', function () {
    $response = $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/pack", ['quantity' => 2], $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Stock marked as packed',
            'data' => [
                'reservation_id' => $this->reservation->id,
                'quantity_packed' => 2,
                'quantity_picked' => 3,
                'status' => 'picked',
            ],
        ]);

    $this->reservation->refresh();
    expect($this->reservation->status)->toBe(ReservationStatus::Picked)
        ->and($this->reservation->quantity_packed)->toBe(2);

    $this->inventory->refresh();
    expect($this->inventory->quantity_picked)->toBe(3)
        ->and($this->inventory->quantity_packed)->toBe(2);
});

test('packing more than picked quantity returns 422', function () {
    $response = $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/pack", ['quantity' => 6], $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
        ]);

    $this->inventory->refresh();
    expect($this->inventory->quantity_picked)->toBe(5)
        ->and($this->inventory->quantity_packed)->toBe(0);
});

test('packing more than remaining pickable quantity returns 422', function () {
    // 5 picked, pack 3 first
    $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/pack", ['quantity' => 3], $this->headers)
        ->assertStatus(200);

    // Remaining pickable = 5 - 3 = 2. Trying to pack 3 more should fail.
    $response = $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/pack", ['quantity' => 3], $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
        ]);
});

test('packing on open reservation with zero picked returns 422', function () {
    $this->reservation->update(['status' => ReservationStatus::Open, 'quantity_picked' => 0]);
    $this->inventory->update(['quantity_picked' => 0, 'quantity_reserved' => 5]);

    $response = $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/pack", ['quantity' => 1], $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
        ]);
});

test('packing released reservation returns 409', function () {
    $this->reservation->update(['status' => ReservationStatus::Released]);

    $response = $this->actingAs($this->warehouseOperator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/pack", ['quantity' => 1], $this->headers);

    $response->assertStatus(409)
        ->assertJson([
            'ok' => false,
            'code' => 409,
            'message' => 'Reservation cannot be packed in its current state',
        ]);
});

test('non-warehouse operator cannot pack reservation', function () {
    $response = $this->actingAs($this->orderCreator)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/pack", ['quantity' => 5], $this->headers);

    $response->assertStatus(403);
});
