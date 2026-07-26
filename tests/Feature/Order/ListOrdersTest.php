<?php

declare(strict_types=1);

use App\Core\Enums\ReservationStatus;
use App\Core\Enums\UserRole;
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

    $this->orderCreator1 = User::factory()->create(['role' => UserRole::OrderCreator]);
    $this->orderCreator2 = User::factory()->create(['role' => UserRole::OrderCreator]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->warehouse = Warehouse::factory()->create(['is_active' => true]);
    $this->product = Product::factory()->create();

    $this->order1 = SalesOrder::factory()->create(['user_id' => $this->orderCreator1->id]);
    $this->orderLine1 = OrderLine::factory()->create([
        'sales_order_id' => $this->order1->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
    ]);
    $this->reservation1 = Reservation::factory()->create([
        'order_line_id' => $this->orderLine1->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => ReservationStatus::Open,
    ]);

    $this->order2 = SalesOrder::factory()->create(['user_id' => $this->orderCreator2->id]);
    $this->orderLine2 = OrderLine::factory()->create([
        'sales_order_id' => $this->order2->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
    ]);
    $this->reservation2 = Reservation::factory()->create([
        'order_line_id' => $this->orderLine2->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => ReservationStatus::Released,
    ]);
});

test('order creator sees only own orders in list', function () {
    $response = $this->actingAs($this->orderCreator1)
        ->getJson('/api/v1/orders', $this->headers);

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['order_id'])->toBe($this->order1->id);
});

test('admin sees all orders in list', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/orders', $this->headers);

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(2);
});

test('order creator can view single own order details', function () {
    $response = $this->actingAs($this->orderCreator1)
        ->getJson("/api/v1/orders/{$this->order1->id}", $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'data' => [
                'order_id' => $this->order1->id,
                'lines' => [
                    [
                        'order_line_id' => $this->orderLine1->id,
                        'reservation_status' => 'open',
                    ],
                ],
            ],
        ]);
});

test('order creator requesting another users order returns 404', function () {
    $response = $this->actingAs($this->orderCreator1)
        ->getJson("/api/v1/orders/{$this->order2->id}", $this->headers);

    $response->assertStatus(404);
});

test('orders filter consumed=true returns only orders with non-released/non-expired reservations', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/orders?consumed=true', $this->headers);

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['order_id'])->toBe($this->order1->id);
});
