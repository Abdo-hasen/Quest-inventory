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
        'quantity_picked' => 0,
        'quantity_packed' => 0,
        'quantity_shipped' => 0,
        'quantity_released' => 0,
        'status' => ReservationStatus::Open,
        'expires_at' => now()->addMinutes(30),
    ]);
});

test('partial cancel reduces quantity and releases unconsumed stock', function () {
    $response = $this->actingAs($this->warehouseOperator)
        ->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->orderLine->id}", [
            'quantity' => 2,
        ], $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Order line updated',
            'data' => [
                'order_line_id' => $this->orderLine->id,
                'quantity' => 2,
                'reservation_status' => 'open',
            ],
        ]);

    $this->orderLine->refresh();
    expect($this->orderLine->quantity)->toBe(2);

    $this->reservation->refresh();
    expect($this->reservation->quantity)->toBe(2)
        ->and($this->reservation->quantity_released)->toBe(3)
        ->and($this->reservation->status)->toBe(ReservationStatus::Open);

    $this->inventory->refresh();
    expect($this->inventory->quantity_reserved)->toBe(2)
        ->and($this->inventory->quantity_available)->toBe(13);

    $movement = InventoryMovement::where('related_reservation_id', $this->reservation->id)->first();
    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe(MovementType::Release)
        ->and($movement->quantity_delta)->toBe(-3);

    $history = ReservationHistory::where('reservation_id', $this->reservation->id)->first();
    expect($history)->not->toBeNull()
        ->and($history->from_status)->toBe(ReservationStatus::Open)
        ->and($history->to_status)->toBe(ReservationStatus::Open)
        ->and($history->quantity_affected)->toBe(3);
});

test('partial cancel to 0 performs full release of reservation', function () {
    $response = $this->actingAs($this->warehouseOperator)
        ->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->orderLine->id}", [
            'quantity' => 0,
        ], $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'data' => [
                'order_line_id' => $this->orderLine->id,
                'quantity' => 0,
                'reservation_status' => 'released',
            ],
        ]);

    $this->reservation->refresh();
    expect($this->reservation->status)->toBe(ReservationStatus::Released)
        ->and($this->reservation->quantity_released)->toBe(5);

    $this->inventory->refresh();
    expect($this->inventory->quantity_reserved)->toBe(0)
        ->and($this->inventory->quantity_available)->toBe(15);
});

test('partial cancel below picked quantity returns 422', function () {
    $this->reservation->update(['quantity_picked' => 3]);

    $response = $this->actingAs($this->warehouseOperator)
        ->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->orderLine->id}", [
            'quantity' => 2, // Below picked (3)!
        ], $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
        ]);

    $this->inventory->refresh();
    expect($this->inventory->quantity_reserved)->toBe(5)
        ->and($this->inventory->quantity_available)->toBe(10);
});

test('increasing line quantity returns 422', function () {
    $response = $this->actingAs($this->warehouseOperator)
        ->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->orderLine->id}", [
            'quantity' => 10,
        ], $this->headers);

    $response->assertStatus(422);
});
