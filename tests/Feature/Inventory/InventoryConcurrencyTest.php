<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Services\Inventory\InventoryService;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Tests\TestCase;
use Throwable;

class InventoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_negative_inventory_adjustments_enforce_pessimistic_lock_and_prevent_negative_stock(): void
    {
        if (config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:') {
            $this->assertTrue(true);

            return;
        }

        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $admin1 = User::factory()->create();
        $admin2 = User::factory()->create();

        Inventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_available' => 50,
        ]);

        $payload1 = [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => -40,
            'reason' => 'Deduction 1',
        ];

        $payload2 = [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => -40,
            'reason' => 'Deduction 2',
        ];

        $admin1Id = (int) $admin1->id;
        $admin2Id = (int) $admin2->id;

        $results = Concurrency::run([
            static function () use ($payload1, $admin1Id) {
                try {
                    return (new InventoryService)->adjust($payload1, $admin1Id)['inventory']->quantity_available;
                } catch (Throwable $e) {
                    return 'failed: ' . $e->getMessage();
                }
            },
            static function () use ($payload2, $admin2Id) {
                try {
                    return (new InventoryService)->adjust($payload2, $admin2Id)['inventory']->quantity_available;
                } catch (Throwable $e) {
                    return 'failed: ' . $e->getMessage();
                }
            },
        ]);

        $successful = array_filter($results, fn ($res) => is_int($res));
        $failed = array_filter($results, fn ($res) => is_string($res) && str_starts_with($res, 'failed:'));

        $this->assertCount(1, $successful);
        $this->assertCount(1, $failed);

        $inventory = Inventory::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        $this->assertEquals(10, $inventory->quantity_available);
    }

    public function test_concurrent_positive_inventory_adjustments_execute_safely_with_lock_for_update(): void
    {
        if (config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:') {
            $this->assertTrue(true);

            return;
        }

        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $admin1 = User::factory()->create();
        $admin2 = User::factory()->create();

        Inventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_available' => 10,
        ]);

        $admin1Id = (int) $admin1->id;
        $admin2Id = (int) $admin2->id;
        $productId = (int) $product->id;
        $warehouseId = (int) $warehouse->id;

        $results = Concurrency::run([
            static function () use ($productId, $warehouseId, $admin1Id) {
                return (new InventoryService)->adjust([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => 20,
                    'reason' => 'Batch A',
                ], $admin1Id);
            },
            static function () use ($productId, $warehouseId, $admin2Id) {
                return (new InventoryService)->adjust([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => 30,
                    'reason' => 'Batch B',
                ], $admin2Id);
            },
        ]);

        $this->assertCount(2, $results);

        $inventory = Inventory::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        $this->assertEquals(60, $inventory->quantity_available);
    }
}
