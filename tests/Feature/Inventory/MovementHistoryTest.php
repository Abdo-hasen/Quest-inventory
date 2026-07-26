<?php

declare(strict_types=1);

use App\Core\Enums\MovementType;
use App\Core\Enums\UserRole;
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

    $this->operator = User::factory()->create(['role' => UserRole::WarehouseOperator, 'name' => 'John Operator']);
    $this->warehouse1 = Warehouse::factory()->create(['is_active' => true]);
    $this->warehouse2 = Warehouse::factory()->create(['is_active' => true]);
    $this->product = Product::factory()->create();

    $this->movement1 = InventoryMovement::factory()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse1->id,
        'type' => MovementType::Adjustment,
        'quantity_delta' => 10,
        'actor_id' => $this->operator->id,
        'created_at' => now()->subHours(2),
    ]);

    $this->movement2 = InventoryMovement::factory()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse2->id,
        'type' => MovementType::Reserve,
        'quantity_delta' => 5,
        'actor_id' => null,
        'created_at' => now()->subHour(),
    ]);
});

test('warehouse operator can fetch paginated movement history newest-first with actor_name', function () {
    $response = $this->actingAs($this->operator)
        ->getJson("/api/v1/inventory/{$this->product->id}/movements?page=1&per_page=20", $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'totalDataCount' => 2,
        ]);

    $data = $response->json('data');
    expect($data[0]['id'])->toBe($this->movement2->id)
        ->and($data[0]['actor_name'])->toBe('System')
        ->and($data[1]['id'])->toBe($this->movement1->id)
        ->and($data[1]['actor_name'])->toBe('John Operator');
});

test('movements can be filtered by warehouse_id', function () {
    $response = $this->actingAs($this->operator)
        ->getJson("/api/v1/inventory/{$this->product->id}/movements?warehouse_id={$this->warehouse1->id}", $this->headers);

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($this->movement1->id);
});
