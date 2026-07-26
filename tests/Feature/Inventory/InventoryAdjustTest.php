<?php

declare(strict_types=1);

use App\Core\Enums\MovementType;
use App\Core\Enums\UserRole;
use App\Models\Inventory;
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

    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->orderCreator = User::factory()->create([
        'role' => UserRole::OrderCreator,
    ]);

    $this->warehouseOperator = User::factory()->create([
        'role' => UserRole::WarehouseOperator,
    ]);
});

it('allows admin to perform baseline stock adjustment creating new inventory row', function () {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $payload = [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 500,
        'reason' => 'Initial stock seeding',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/inventory/adjust', $payload, $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Stock adjusted',
            'data' => [
                'inventory' => [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_available' => 500,
                ],
                'movement' => [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'type' => MovementType::Adjustment->value,
                    'quantity_delta' => 500,
                    'reason' => 'Initial stock seeding',
                    'actor_id' => $this->admin->id,
                ],
            ],
        ]);

    $this->assertDatabaseHas('inventory', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 500,
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'type' => 'adjustment',
        'quantity_delta' => 500,
        'reason' => 'Initial stock seeding',
        'actor_id' => $this->admin->id,
    ]);
});

it('allows admin to perform positive and negative adjustments on existing inventory', function () {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();

    Inventory::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 500,
    ]);

    // Deduction of 50
    $payload = [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => -50,
        'reason' => 'Stock audit correction',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/inventory/adjust', $payload, $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Stock adjusted',
            'data' => [
                'inventory' => [
                    'quantity_available' => 450,
                ],
                'movement' => [
                    'quantity_delta' => -50,
                    'reason' => 'Stock audit correction',
                ],
            ],
        ]);

    $this->assertDatabaseHas('inventory', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 450,
    ]);
});

it('rejects adjustment that brings available stock below zero with 422', function () {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();

    Inventory::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 100,
    ]);

    $payload = [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => -1000,
        'reason' => 'Excessive deduction attempt',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/inventory/adjust', $payload, $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
            'data' => [
                'errors' => [
                    'quantity' => [
                        'Adjustment would bring available stock below zero',
                    ],
                ],
            ],
        ]);

    // Stock should remain unchanged
    $this->assertDatabaseHas('inventory', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 100,
    ]);
});

it('rejects negative adjustment on non-existent inventory row with 422', function () {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $payload = [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => -10,
        'reason' => 'Negative adjustment on non-existent stock',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/inventory/adjust', $payload, $this->headers);

    $response->assertStatus(422);

    $this->assertDatabaseMissing('inventory', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
    ]);
});

it('rejects zero quantity adjustment with 422 validation error', function () {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $payload = [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 0,
        'reason' => 'Zero quantity adjustment',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/inventory/adjust', $payload, $this->headers);

    $response->assertStatus(422);
});

it('blocks non-admin callers with 403', function () {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $payload = [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 100,
        'reason' => 'Unauthorized adjustment attempt',
    ];

    $responseOrderCreator = $this->actingAs($this->orderCreator)
        ->postJson('/api/v1/inventory/adjust', $payload, $this->headers);

    $responseOrderCreator->assertStatus(403);

    $responseOperator = $this->actingAs($this->warehouseOperator)
        ->postJson('/api/v1/inventory/adjust', $payload, $this->headers);

    $responseOperator->assertStatus(403);
});

it('prevents soft deleting a product with existing inventory records returning 422', function () {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();

    Inventory::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 50,
    ]);

    $response = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/products/{$product->id}", [], $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
            'data' => [
                'errors' => [
                    'product' => [
                        'Cannot delete a product with active inventory',
                    ],
                ],
            ],
        ]);

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'deleted_at' => null,
    ]);
});

it('rejects inventory adjustment for inactive warehouse with 422', function () {
    $product = Product::factory()->create();
    $inactiveWarehouse = Warehouse::factory()->create([
        'is_active' => false,
    ]);

    $payload = [
        'product_id' => $product->id,
        'warehouse_id' => $inactiveWarehouse->id,
        'quantity' => 100,
        'reason' => 'Inactive warehouse adjustment attempt',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/inventory/adjust', $payload, $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
            'data' => [
                'errors' => [
                    'warehouse_id' => [
                        'The selected warehouse is invalid.',
                    ],
                ],
            ],
        ]);
});
