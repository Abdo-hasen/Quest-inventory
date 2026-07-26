<?php

declare(strict_types=1);

use App\Core\Enums\MovementType;
use App\Core\Enums\UserRole;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->headers = [
        'Accept' => 'application/json',
        'X-API-KEY' => 'secret_app_key_123',
    ];

    $this->operator = User::factory()->create([
        'role' => UserRole::WarehouseOperator,
        'name' => 'Transfer Operator',
    ]);

    $this->warehouseFrom = Warehouse::factory()->create(['is_active' => true]);
    $this->warehouseTo = Warehouse::factory()->create(['is_active' => true]);
    $this->product = Product::factory()->create();

    $this->sourceInventory = Inventory::factory()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouseFrom->id,
        'quantity_available' => 10,
        'quantity_reserved' => 0,
        'quantity_picked' => 0,
        'quantity_packed' => 0,
        'quantity_shipped' => 0,
    ]);
});

test('warehouse operator can transfer available stock between warehouses', function () {
    $response = $this->actingAs($this->operator)
        ->postJson('/api/v1/inventory/transfer', [
            'product_id' => $this->product->id,
            'from_warehouse_id' => $this->warehouseFrom->id,
            'to_warehouse_id' => $this->warehouseTo->id,
            'quantity' => 4,
        ], $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Stock transferred',
            'data' => [
                'product_id' => $this->product->id,
                'from_warehouse_id' => $this->warehouseFrom->id,
                'to_warehouse_id' => $this->warehouseTo->id,
                'quantity' => 4,
            ],
        ]);

    expect($this->sourceInventory->fresh()->quantity_available)->toBe(6);

    $destInventory = Inventory::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouseTo->id)
        ->first();

    expect($destInventory)->not->toBeNull()
        ->and($destInventory->quantity_available)->toBe(4);

    $movements = InventoryMovement::query()
        ->where('product_id', $this->product->id)
        ->get();

    expect($movements)->toHaveCount(2);

    $outMovement = $movements->firstWhere('type', MovementType::TransferOut);
    $inMovement = $movements->firstWhere('type', MovementType::TransferIn);

    expect($outMovement)->not->toBeNull()
        ->and($outMovement->quantity_delta)->toBe(-4)
        ->and($outMovement->warehouse_id)->toBe($this->warehouseFrom->id)
        ->and($inMovement)->not->toBeNull()
        ->and($inMovement->quantity_delta)->toBe(4)
        ->and($inMovement->warehouse_id)->toBe($this->warehouseTo->id);
});

test('transfer creates destination inventory row if missing', function () {
    expect(Inventory::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouseTo->id)
        ->exists())->toBeFalse();

    $response = $this->actingAs($this->operator)
        ->postJson('/api/v1/inventory/transfer', [
            'product_id' => $this->product->id,
            'from_warehouse_id' => $this->warehouseFrom->id,
            'to_warehouse_id' => $this->warehouseTo->id,
            'quantity' => 5,
        ], $this->headers);

    $response->assertStatus(200);

    $destInventory = Inventory::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouseTo->id)
        ->first();

    expect($destInventory)->not->toBeNull()
        ->and($destInventory->quantity_available)->toBe(5)
        ->and($destInventory->quantity_reserved)->toBe(0);
});

test('transfer fails with 422 if available stock is insufficient', function () {
    $response = $this->actingAs($this->operator)
        ->postJson('/api/v1/inventory/transfer', [
            'product_id' => $this->product->id,
            'from_warehouse_id' => $this->warehouseFrom->id,
            'to_warehouse_id' => $this->warehouseTo->id,
            'quantity' => 15,
        ], $this->headers);

    $response->assertStatus(422);

    expect($this->sourceInventory->fresh()->quantity_available)->toBe(10);
    expect(InventoryMovement::query()->count())->toBe(0);
});

test('reserved stock is not counted as available for transfer', function () {
    $this->sourceInventory->update([
        'quantity_available' => 2,
        'quantity_reserved' => 10,
    ]);

    $response = $this->actingAs($this->operator)
        ->postJson('/api/v1/inventory/transfer', [
            'product_id' => $this->product->id,
            'from_warehouse_id' => $this->warehouseFrom->id,
            'to_warehouse_id' => $this->warehouseTo->id,
            'quantity' => 5,
        ], $this->headers);

    $response->assertStatus(422);
    expect($this->sourceInventory->fresh()->quantity_available)->toBe(2);
});

test('transfer to same warehouse fails validation with 422', function () {
    $response = $this->actingAs($this->operator)
        ->postJson('/api/v1/inventory/transfer', [
            'product_id' => $this->product->id,
            'from_warehouse_id' => $this->warehouseFrom->id,
            'to_warehouse_id' => $this->warehouseFrom->id,
            'quantity' => 2,
        ], $this->headers);

    $response->assertStatus(422);
    expect($response->json('data.errors'))->toHaveKey('to_warehouse_id');
});

test('transfer to inactive destination warehouse fails validation with 422', function () {
    $inactiveWarehouse = Warehouse::factory()->create(['is_active' => false]);

    $response = $this->actingAs($this->operator)
        ->postJson('/api/v1/inventory/transfer', [
            'product_id' => $this->product->id,
            'from_warehouse_id' => $this->warehouseFrom->id,
            'to_warehouse_id' => $inactiveWarehouse->id,
            'quantity' => 2,
        ], $this->headers);

    $response->assertStatus(422);
    expect($response->json('data.errors'))->toHaveKey('to_warehouse_id');
});

test('sequential transfers succeed and lock in ascending id order', function () {
    $response1 = $this->actingAs($this->operator)
        ->postJson('/api/v1/inventory/transfer', [
            'product_id' => $this->product->id,
            'from_warehouse_id' => $this->warehouseFrom->id,
            'to_warehouse_id' => $this->warehouseTo->id,
            'quantity' => 4,
        ], $this->headers);

    $response2 = $this->actingAs($this->operator)
        ->postJson('/api/v1/inventory/transfer', [
            'product_id' => $this->product->id,
            'from_warehouse_id' => $this->warehouseFrom->id,
            'to_warehouse_id' => $this->warehouseTo->id,
            'quantity' => 4,
        ], $this->headers);

    $response1->assertStatus(200);
    $response2->assertStatus(200);

    expect($this->sourceInventory->fresh()->quantity_available)->toBe(2);

    $destInventory = Inventory::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouseTo->id)
        ->first();

    expect($destInventory->quantity_available)->toBe(8);
});
