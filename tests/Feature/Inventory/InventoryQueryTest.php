<?php

declare(strict_types=1);

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

    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->warehouse1 = Warehouse::factory()->create(['is_active' => true]);
    $this->warehouse2 = Warehouse::factory()->create(['is_active' => true]);
    $this->product = Product::factory()->create();

    $this->inventory1 = Inventory::factory()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse1->id,
        'quantity_available' => 10,
        'quantity_reserved' => 5,
        'quantity_picked' => 2,
        'quantity_packed' => 1,
        'quantity_shipped' => 3,
    ]);

    $this->inventory2 = Inventory::factory()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse2->id,
        'quantity_available' => 20,
        'quantity_reserved' => 0,
        'quantity_picked' => 0,
        'quantity_packed' => 0,
        'quantity_shipped' => 0,
    ]);
});

test('query inventory for single warehouse', function () {
    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/inventory?product_id={$this->product->id}&warehouse_id={$this->warehouse1->id}", $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'data' => [
                'id' => $this->inventory1->id,
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse1->id,
                'quantity_available' => 10,
                'quantity_reserved' => 5,
                'quantity_picked' => 2,
                'quantity_packed' => 1,
                'quantity_shipped' => 3,
            ],
        ]);
});

test('query inventory without warehouse_id returns array of all warehouse rows', function () {
    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/inventory?product_id={$this->product->id}", $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
        ]);

    expect($response->json('data'))->toHaveCount(2);
});

test('query inventory for non-existent row returns zeros object instead of 404', function () {
    $newWarehouse = Warehouse::factory()->create(['is_active' => true]);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/inventory?product_id={$this->product->id}&warehouse_id={$newWarehouse->id}", $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'data' => [
                'id' => null,
                'product_id' => $this->product->id,
                'warehouse_id' => $newWarehouse->id,
                'quantity_available' => 0,
                'quantity_reserved' => 0,
                'quantity_picked' => 0,
                'quantity_packed' => 0,
                'quantity_shipped' => 0,
            ],
        ]);
});

test('unauthenticated inventory query returns 401', function () {
    $response = $this->getJson("/api/v1/inventory?product_id={$this->product->id}", $this->headers);

    $response->assertStatus(401);
});
