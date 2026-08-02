<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->orderCreator1 = User::factory()->create(['role' => UserRole::OrderCreator]);
    $this->orderCreator2 = User::factory()->create(['role' => UserRole::OrderCreator]);

    $this->warehouse = Warehouse::factory()->create(['is_active' => true]);
    $this->product = Product::factory()->create();

    $this->inventory = Inventory::factory()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 10,
        'quantity_reserved' => 0,
    ]);
});

test('submitting with same idempotency key returns cached 201 response', function () {
    $payload = [
        'lines' => [
            [
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 2,
            ],
        ],
    ];

    $headers = [
        'Accept' => 'application/json',
        'X-API-KEY' => 'secret_app_key_123',
        'Idempotency-Key' => 'unique-order-key-123',
    ];

    $response1 = $this->actingAs($this->orderCreator1)
        ->postJson('/api/v1/orders', $payload, $headers);

    $response1->assertStatus(201);
    $orderId1 = $response1->json('data.order_id');

    expect(SalesOrder::count())->toBe(1)
        ->and(Reservation::count())->toBe(1)
        ->and(Cache::has("idempotency:{$this->orderCreator1->id}:unique-order-key-123"))->toBeTrue();

    $this->inventory->refresh();
    expect($this->inventory->quantity_available)->toBe(8)
        ->and($this->inventory->quantity_reserved)->toBe(2);

    $response2 = $this->actingAs($this->orderCreator1)
        ->postJson('/api/v1/orders', $payload, $headers);

    $response2->assertStatus(201);
    $orderId2 = $response2->json('data.order_id');

    expect($orderId2)->toBe($orderId1)
        ->and(SalesOrder::count())->toBe(1)
        ->and(Reservation::count())->toBe(1);

    $this->inventory->refresh();
    expect($this->inventory->quantity_available)->toBe(8)
        ->and($this->inventory->quantity_reserved)->toBe(2);
});

test('idempotency key is scoped per user', function () {
    $payload = [
        'lines' => [
            [
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
            ],
        ],
    ];

    $headers = [
        'Accept' => 'application/json',
        'X-API-KEY' => 'secret_app_key_123',
        'Idempotency-Key' => 'shared-key-abc',
    ];

    $response1 = $this->actingAs($this->orderCreator1)
        ->postJson('/api/v1/orders', $payload, $headers);

    $response1->assertStatus(201);
    $orderId1 = $response1->json('data.order_id');

    $response2 = $this->actingAs($this->orderCreator2)
        ->postJson('/api/v1/orders', $payload, $headers);

    $response2->assertStatus(201);
    $orderId2 = $response2->json('data.order_id');

    expect($orderId2)->not->toBe($orderId1)
        ->and(SalesOrder::count())->toBe(2);
});
