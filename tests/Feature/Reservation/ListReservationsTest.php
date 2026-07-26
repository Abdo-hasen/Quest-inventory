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

    $this->operator = User::factory()->create(['role' => UserRole::WarehouseOperator]);
    $this->warehouse = Warehouse::factory()->create(['is_active' => true]);
    $this->product = Product::factory()->create();
    $this->order = SalesOrder::factory()->create();

    $this->orderLine = OrderLine::factory()->create([
        'sales_order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
    ]);

    $this->openReservation = Reservation::factory()->create([
        'order_line_id' => $this->orderLine->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => ReservationStatus::Open,
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->releasedReservation = Reservation::factory()->create([
        'order_line_id' => $this->orderLine->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => ReservationStatus::Released,
        'expires_at' => now()->subMinutes(10),
    ]);
});

test('warehouse operator can list active reservations by default excluding released and expired', function () {
    $response = $this->actingAs($this->operator)
        ->getJson('/api/v1/reservations', $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
        ]);

    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($this->openReservation->id)
        ->and($data[0]['order_reference'])->toBe($this->order->id);
});

test('warehouse operator can list reservations filtered by explicit status', function () {
    $response = $this->actingAs($this->operator)
        ->getJson('/api/v1/reservations?status=released', $this->headers);

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($this->releasedReservation->id);
});
