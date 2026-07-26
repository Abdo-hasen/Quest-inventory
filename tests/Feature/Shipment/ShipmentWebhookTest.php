<?php

declare(strict_types=1);

use App\Core\Enums\MovementType;
use App\Core\Enums\ReservationStatus;
use App\Core\Enums\ShipmentStatus;
use App\Core\Services\Shipment\ShipmentService;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\OrderLine;
use App\Models\ProcessedWebhookEvent;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->headers = [
        'Accept' => 'application/json',
        'X-API-KEY' => 'secret_app_key_123',
    ];

    $this->warehouse = Warehouse::factory()->create(['is_active' => true]);
    $this->product = Product::factory()->create();

    $this->inventory = Inventory::factory()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 5,
        'quantity_reserved' => 0,
        'quantity_picked' => 0,
        'quantity_packed' => 5,
        'quantity_shipped' => 0,
    ]);

    $this->order = SalesOrder::factory()->create();

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
        'quantity_packed' => 5,
        'quantity_shipped' => 0,
        'status' => ReservationStatus::Packed,
    ]);

    $this->shipment = Shipment::factory()->create([
        'reservation_id' => $this->reservation->id,
        'quantity' => 5,
        'status' => ShipmentStatus::InTransit,
    ]);
});

test('webhook processes shipment confirmation once', function () {
    $payload = [
        'event_id' => 'evt_abc123',
        'shipment_id' => $this->shipment->id,
        'status' => 'success',
        'quantity_shipped' => 5,
    ];

    $response = $this->postJson('/api/v1/webhooks/shipping', $payload, $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Webhook processed',
        ]);

    $this->shipment->refresh();
    expect($this->shipment->status)->toBe(ShipmentStatus::Shipped);

    $this->reservation->refresh();
    expect($this->reservation->status)->toBe(ReservationStatus::Fulfilled);

    $this->inventory->refresh();
    expect($this->inventory->quantity_packed)->toBe(0)
        ->and($this->inventory->quantity_shipped)->toBe(5);

    expect(ProcessedWebhookEvent::where('event_id', 'evt_abc123')->exists())->toBeTrue();
});

test('duplicate webhook returns 200 no-op without double inventory deduction', function () {
    $payload = [
        'event_id' => 'evt_abc123',
        'shipment_id' => $this->shipment->id,
        'status' => 'success',
        'quantity_shipped' => 5,
    ];

    // First call
    $this->postJson('/api/v1/webhooks/shipping', $payload, $this->headers)->assertStatus(200);

    // Second call with same event_id
    $response = $this->postJson('/api/v1/webhooks/shipping', $payload, $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Event already processed',
        ]);

    $this->inventory->refresh();
    expect($this->inventory->quantity_packed)->toBe(0)
        ->and($this->inventory->quantity_shipped)->toBe(5);

    $movementsCount = InventoryMovement::where('related_reservation_id', $this->reservation->id)
        ->where('type', MovementType::Ship->value)
        ->count();

    expect($movementsCount)->toBe(1);
});

test('webhook for unknown shipment_id returns 422', function () {
    $payload = [
        'event_id' => 'evt_unknown_999',
        'shipment_id' => 999999,
        'status' => 'success',
        'quantity_shipped' => 5,
    ];

    $response = $this->postJson('/api/v1/webhooks/shipping', $payload, $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
        ]);
});

test('webhook handles permanent failure without requiring quantity_shipped', function () {
    $payload = [
        'event_id' => 'evt_fail_123',
        'shipment_id' => $this->shipment->id,
        'status' => 'permanent_failure',
    ];

    $response = $this->postJson('/api/v1/webhooks/shipping', $payload, $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Webhook processed',
        ]);

    $this->shipment->refresh();
    expect($this->shipment->status)->toBe(ShipmentStatus::Failed);
});

test('markFailed on already shipped shipment does not downgrade status', function () {
    $this->shipment->update(['status' => ShipmentStatus::Shipped]);

    $service = app(ShipmentService::class);
    $service->markFailed($this->shipment, 'Late failure webhook');

    $this->shipment->refresh();
    expect($this->shipment->status)->toBe(ShipmentStatus::Shipped);
});
