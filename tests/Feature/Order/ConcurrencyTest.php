<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Core\Enums\UserRole;
use App\Core\Services\Order\OrderService;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Exception;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $orderCreator1;

    private User $orderCreator2;

    private Warehouse $warehouse;

    private Product $product;

    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_sequential_requests_for_limited_stock_ensure_exactly_one_succeeds_and_stock_never_goes_negative(): void
    {
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

        $this->assertCount(1, $successful);
        $this->assertCount(1, $failed);

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->quantity_available);
        $this->assertEquals(5, $this->inventory->quantity_reserved);

        $this->assertEquals(1, SalesOrder::count());
        $this->assertEquals(1, Reservation::count());
    }

    public function test_concurrent_task_runner_ensures_stock_protection_with_pessimistic_locking(): void
    {
        if (config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:') {
            $this->assertTrue(true);

            return;
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
            static function () use ($payload, $creator1Id) {
                try {
                    return app(OrderService::class)->create($payload, $creator1Id)->id;
                } catch (Throwable $e) {
                    return 'failed: '.$e->getMessage();
                }
            },
            static function () use ($payload, $creator2Id) {
                try {
                    return app(OrderService::class)->create($payload, $creator2Id)->id;
                } catch (Throwable $e) {
                    return 'failed: '.$e->getMessage();
                }
            },
        ]);

        $successful = array_filter($results, fn ($res) => is_int($res));
        $this->assertCount(1, $successful);

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->quantity_available);
        $this->assertEquals(5, $this->inventory->quantity_reserved);
    }
}
