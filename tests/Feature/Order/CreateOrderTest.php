<?php

declare(strict_types=1);

use App\Core\Enums\MovementType;
use App\Core\Enums\OrderStatus;
use App\Core\Enums\ReservationStatus;
use App\Core\Enums\UserRole;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Reservation;
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

    $this->orderCreator = User::factory()->create(['role' => UserRole::OrderCreator]);
    $this->warehouseOperator = User::factory()->create(['role' => UserRole::WarehouseOperator]);

    $this->warehouse = Warehouse::factory()->create(['is_active' => true]);
    $this->product1 = Product::factory()->create();
    $this->product2 = Product::factory()->create();

    $this->inventory1 = Inventory::factory()->create([
        'product_id' => $this->product1->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 10,
        'quantity_reserved' => 0,
    ]);

    $this->inventory2 = Inventory::factory()->create([
        'product_id' => $this->product2->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 5,
        'quantity_reserved' => 0,
    ]);
});

test('order creator can create single-line order and reserve stock', function () {
    $payload = [
        'lines' => [
            [
                'product_id' => $this->product1->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 4,
            ],
        ],
    ];

    $response = $this->actingAs($this->orderCreator)
        ->postJson('/api/v1/orders', $payload, $this->headers);

    $response->assertStatus(201)
        ->assertJson([
            'ok' => true,
            'code' => 201,
            'message' => 'Order created',
        ])
        ->assertJsonStructure([
            'ok',
            'code',
            'message',
            'direct',
            'data' => [
                'order_id',
                'status',
                'lines' => [
                    '*' => [
                        'order_line_id',
                        'product_id',
                        'warehouse_id',
                        'quantity',
                        'reservation_id',
                        'reservation_status',
                        'expires_at',
                    ],
                ],
            ],
        ]);

    $orderId = $response->json('data.order_id');
    expect(SalesOrder::find($orderId))->not->toBeNull()
        ->and(SalesOrder::find($orderId)->status)->toBe(OrderStatus::Open);

    $this->inventory1->refresh();
    expect($this->inventory1->quantity_available)->toBe(6)
        ->and($this->inventory1->quantity_reserved)->toBe(4);

    $orderLine = OrderLine::where('sales_order_id', $orderId)->first();
    expect($orderLine)->not->toBeNull()
        ->and($orderLine->quantity)->toBe(4);

    $reservation = Reservation::where('order_line_id', $orderLine->id)->first();
    expect($reservation)->not->toBeNull()
        ->and($reservation->quantity)->toBe(4)
        ->and($reservation->status)->toBe(ReservationStatus::Open);

    $movement = InventoryMovement::where('related_order_id', $orderId)->first();
    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe(MovementType::Reserve)
        ->and($movement->quantity_delta)->toBe(4)
        ->and($movement->related_reservation_id)->toBe($reservation->id);
});

test('multi-line order reserves all lines atomically', function () {
    $payload = [
        'lines' => [
            [
                'product_id' => $this->product1->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 3,
            ],
            [
                'product_id' => $this->product2->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 2,
            ],
        ],
    ];

    $response = $this->actingAs($this->orderCreator)
        ->postJson('/api/v1/orders', $payload, $this->headers);

    $response->assertStatus(201);

    $this->inventory1->refresh();
    $this->inventory2->refresh();

    expect($this->inventory1->quantity_available)->toBe(7)
        ->and($this->inventory1->quantity_reserved)->toBe(3)
        ->and($this->inventory2->quantity_available)->toBe(3)
        ->and($this->inventory2->quantity_reserved)->toBe(2);

    expect(Reservation::count())->toBe(2);
});

test('order creation rolls back fully if one line has insufficient stock', function () {
    $payload = [
        'lines' => [
            [
                'product_id' => $this->product1->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 2,
            ],
            [
                'product_id' => $this->product2->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 999, // Insufficient!
            ],
        ],
    ];

    $response = $this->actingAs($this->orderCreator)
        ->postJson('/api/v1/orders', $payload, $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
        ]);

    $this->inventory1->refresh();
    $this->inventory2->refresh();

    expect($this->inventory1->quantity_available)->toBe(10)
        ->and($this->inventory1->quantity_reserved)->toBe(0)
        ->and($this->inventory2->quantity_available)->toBe(5)
        ->and($this->inventory2->quantity_reserved)->toBe(0);

    expect(SalesOrder::count())->toBe(0)
        ->and(Reservation::count())->toBe(0)
        ->and(InventoryMovement::count())->toBe(0);
});

test('order creation fails if warehouse is inactive', function () {
    $inactiveWarehouse = Warehouse::factory()->create(['is_active' => false]);

    $payload = [
        'lines' => [
            [
                'product_id' => $this->product1->id,
                'warehouse_id' => $inactiveWarehouse->id,
                'quantity' => 1,
            ],
        ],
    ];

    $response = $this->actingAs($this->orderCreator)
        ->postJson('/api/v1/orders', $payload, $this->headers);

    $response->assertStatus(422);
});

test('order creation fails if product is soft-deleted', function () {
    $this->product1->delete();

    $payload = [
        'lines' => [
            [
                'product_id' => $this->product1->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
            ],
        ],
    ];

    $response = $this->actingAs($this->orderCreator)
        ->postJson('/api/v1/orders', $payload, $this->headers);

    $response->assertStatus(422);
});

test('unauthenticated users cannot create orders', function () {
    $payload = [
        'lines' => [
            [
                'product_id' => $this->product1->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
            ],
        ],
    ];

    $response = $this->postJson('/api/v1/orders', $payload, $this->headers);

    $response->assertStatus(401);
});

test('users without create-orders permission get 403', function () {
    $payload = [
        'lines' => [
            [
                'product_id' => $this->product1->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
            ],
        ],
    ];

    $response = $this->actingAs($this->warehouseOperator)
        ->postJson('/api/v1/orders', $payload, $this->headers);

    $response->assertStatus(403);
});
