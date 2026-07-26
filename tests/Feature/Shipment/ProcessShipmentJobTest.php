<?php

declare(strict_types=1);

use App\Core\Enums\MovementType;
use App\Core\Enums\ReservationStatus;
use App\Core\Enums\ShipmentAttemptStatus;
use App\Core\Enums\ShipmentStatus;
use App\Core\Helpers\Shipping\MockShippingProvider;
use App\Core\Helpers\Shipping\ShippingProviderInterface;
use App\Core\Services\Shipment\ShipmentService;
use App\Jobs\CheckShipmentStatusJob;
use App\Jobs\ProcessShipmentJob;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\ShipmentAttempt;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
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
});

test('success outcome updates inventory, reservation to fulfilled, shipment to shipped, creates movement', function () {
    $this->app->instance(ShippingProviderInterface::class, new MockShippingProvider('success'));

    $shipment = Shipment::factory()->create([
        'reservation_id' => $this->reservation->id,
        'quantity' => 5,
        'status' => ShipmentStatus::Pending,
    ]);

    $job = new ProcessShipmentJob($shipment);
    $job->handle(app(ShipmentService::class));

    $shipment->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::Shipped);

    $this->reservation->refresh();
    expect($this->reservation->status)->toBe(ReservationStatus::Fulfilled)
        ->and($this->reservation->quantity_shipped)->toBe(5);

    $this->inventory->refresh();
    expect($this->inventory->quantity_packed)->toBe(0)
        ->and($this->inventory->quantity_shipped)->toBe(5);

    $movement = InventoryMovement::where('related_reservation_id', $this->reservation->id)
        ->where('type', MovementType::Ship->value)
        ->first();
    expect($movement)->not->toBeNull()
        ->and($movement->quantity_delta)->toBe(5);

    $attempt = ShipmentAttempt::where('shipment_id', $shipment->id)->first();
    expect($attempt)->not->toBeNull()
        ->and($attempt->status)->toBe(ShipmentAttemptStatus::Success);
});

test('partial shipment updates inventory partially and reservation status to partially_fulfilled', function () {
    $mockProvider = Mockery::mock(ShippingProviderInterface::class);
    $mockProvider->shouldReceive('ship')->once()->andReturn([
        'status' => ShipmentAttemptStatus::Success,
        'quantity_shipped' => 3,
        'raw' => ['scenario' => 'partial_success'],
    ]);
    $this->app->instance(ShippingProviderInterface::class, $mockProvider);

    $shipment = Shipment::factory()->create([
        'reservation_id' => $this->reservation->id,
        'quantity' => 3,
        'status' => ShipmentStatus::Pending,
    ]);

    $job = new ProcessShipmentJob($shipment);
    $job->handle(app(ShipmentService::class));

    $shipment->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::Shipped);

    $this->reservation->refresh();
    expect($this->reservation->status)->toBe(ReservationStatus::PartiallyFulfilled)
        ->and($this->reservation->quantity_shipped)->toBe(3);

    $this->inventory->refresh();
    expect($this->inventory->quantity_packed)->toBe(2)
        ->and($this->inventory->quantity_shipped)->toBe(3);
});

test('permanent failure marks shipment failed without touching inventory', function () {
    $this->app->instance(ShippingProviderInterface::class, new MockShippingProvider('permanent_failure'));

    $shipment = Shipment::factory()->create([
        'reservation_id' => $this->reservation->id,
        'quantity' => 5,
        'status' => ShipmentStatus::Pending,
    ]);

    $job = new ProcessShipmentJob($shipment);
    $job->handle(app(ShipmentService::class));

    $shipment->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::Failed);

    $this->reservation->refresh();
    expect($this->reservation->status)->toBe(ReservationStatus::Packed)
        ->and($this->reservation->quantity_shipped)->toBe(0);

    $this->inventory->refresh();
    expect($this->inventory->quantity_packed)->toBe(5)
        ->and($this->inventory->quantity_shipped)->toBe(0);

    $attempt = ShipmentAttempt::where('shipment_id', $shipment->id)->first();
    expect($attempt)->not->toBeNull()
        ->and($attempt->status)->toBe(ShipmentAttemptStatus::PermanentFailure);
});

test('timeout outcome dispatches CheckShipmentStatusJob and leaves inventory unchanged', function () {
    Queue::fake();
    $this->app->instance(ShippingProviderInterface::class, new MockShippingProvider('timeout'));

    $shipment = Shipment::factory()->create([
        'reservation_id' => $this->reservation->id,
        'quantity' => 5,
        'status' => ShipmentStatus::Pending,
    ]);

    $job = new ProcessShipmentJob($shipment);
    $job->handle(app(ShipmentService::class));

    $shipment->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::Timeout);

    $this->inventory->refresh();
    expect($this->inventory->quantity_packed)->toBe(5)
        ->and($this->inventory->quantity_shipped)->toBe(0);

    Queue::assertPushed(CheckShipmentStatusJob::class);
});

test('crash mid-job rolls back transaction completely leaving inventory unchanged', function () {
    $failingProvider = Mockery::mock(ShippingProviderInterface::class);
    $failingProvider->shouldReceive('ship')->once()->andThrow(new RuntimeException('Network crash mid-job'));
    $this->app->instance(ShippingProviderInterface::class, $failingProvider);

    $shipment = Shipment::factory()->create([
        'reservation_id' => $this->reservation->id,
        'quantity' => 5,
        'status' => ShipmentStatus::Pending,
    ]);

    try {
        $job = new ProcessShipmentJob($shipment);
        $job->handle(app(ShipmentService::class));
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Network crash mid-job');
    }

    $this->inventory->refresh();
    expect($this->inventory->quantity_packed)->toBe(5)
        ->and($this->inventory->quantity_shipped)->toBe(0);

    $this->reservation->refresh();
    expect($this->reservation->status)->toBe(ReservationStatus::Packed);
});

test('late confirmation on already-shipped shipment is a no-op that prevents double deduction', function () {
    $shipment = Shipment::factory()->create([
        'reservation_id' => $this->reservation->id,
        'quantity' => 5,
        'status' => ShipmentStatus::Shipped,
    ]);

    $service = app(ShipmentService::class);
    $service->confirmShipment($shipment, 5);

    $this->inventory->refresh();
    expect($this->inventory->quantity_packed)->toBe(5)
        ->and($this->inventory->quantity_shipped)->toBe(0);
});
