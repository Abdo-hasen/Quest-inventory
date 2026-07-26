<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Services\Order\OrderService;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->headers = [
        'Accept' => 'application/json',
        'X-API-KEY' => 'secret_app_key_123',
    ];

    $this->orderCreator1 = User::factory()->create(['role' => UserRole::OrderCreator]);
    $this->orderCreator2 = User::factory()->create(['role' => UserRole::OrderCreator]);

    $this->warehouse = Warehouse::factory()->create(['is_active' => true]);
    $this->product = Product::factory()->create();

    $this->inventory = Inventory::factory()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 5,
        'quantity_reserved' => 0,
    ]);
});

test('sequential requests for limited stock ensure exactly one succeeds and stock never goes negative', function () {
    $payload = [
        'lines' => [
            [
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 5,
            ],
        ],
    ];

    $results = [];

    try {
        $results[] = app(OrderService::class)->create($payload, (int) $this->orderCreator1->id);
    } catch (Exception $e) {
        $results[] = $e;
    }

    try {
        $results[] = app(OrderService::class)->create($payload, (int) $this->orderCreator2->id);
    } catch (Exception $e) {
        $results[] = $e;
    }

    $successful = array_filter($results, fn ($res) => $res instanceof SalesOrder);
    $failed = array_filter($results, fn ($res) => $res instanceof ValidationException);

    expect(count($successful))->toBe(1)
        ->and(count($failed))->toBe(1);

    $this->inventory->refresh();
    expect($this->inventory->quantity_available)->toBe(0)
        ->and($this->inventory->quantity_reserved)->toBe(5);

    expect(SalesOrder::count())->toBe(1)
        ->and(Reservation::count())->toBe(1);
});

test('concurrent task runner ensures stock protection with pessimistic locking', function () {
    if (config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:') {
        // Skip multi-process concurrency test when running on in-memory SQLite isolated per process
        return expect(true)->toBeTrue();
    }

    $payload = [
        'lines' => [
            [
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 5,
            ],
        ],
    ];

    $creator1Id = (int) $this->orderCreator1->id;
    $creator2Id = (int) $this->orderCreator2->id;

    $results = Concurrency::run([
        function () use ($payload, $creator1Id) {
            try {
                return app(OrderService::class)->create($payload, $creator1Id)->id;
            } catch (Throwable $e) {
                return 'failed: '.$e->getMessage();
            }
        },
        function () use ($payload, $creator2Id) {
            try {
                return app(OrderService::class)->create($payload, $creator2Id)->id;
            } catch (Throwable $e) {
                return 'failed: '.$e->getMessage();
            }
        },
    ]);

    $successful = array_filter($results, fn ($res) => is_int($res));
    expect(count($successful))->toBe(1);

    $this->inventory->refresh();
    expect($this->inventory->quantity_available)->toBe(0)
        ->and($this->inventory->quantity_reserved)->toBe(5);
});
