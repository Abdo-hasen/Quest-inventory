<?php

declare(strict_types=1);

use App\Core\Services\Inventory\InventoryService;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('handles sequential inventory adjustments accurately summing deltas', function () {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $admin = User::factory()->create();

    $service = new InventoryService;

    // Perform multiple adjustments sequentially
    $service->adjust([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 100,
        'reason' => 'Batch 1',
    ], $admin->id);

    $service->adjust([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 50,
        'reason' => 'Batch 2',
    ], $admin->id);

    $service->adjust([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => -30,
        'reason' => 'Deduction 1',
    ], $admin->id);

    $inventory = Inventory::where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->first();

    expect($inventory)->not->toBeNull()
        ->and($inventory->quantity_available)->toBe(120);

    $movementsCount = DB::table('inventory_movements')
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->count();

    expect($movementsCount)->toBe(3);
});
