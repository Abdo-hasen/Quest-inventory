<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
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
});

it('allows admin to create a product', function () {
    $payload = [
        'name' => 'Widget A',
        'sku' => 'WGT-001',
        'description' => 'Standard Widget',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/products', $payload, $this->headers);

    $response->assertStatus(201)
        ->assertJson([
            'ok' => true,
            'code' => 201,
            'message' => 'Product created',
            'direct' => null,
            'data' => [
                'name' => 'Widget A',
                'sku' => 'WGT-001',
                'description' => 'Standard Widget',
            ],
        ]);

    $this->assertDatabaseHas('products', [
        'name' => 'Widget A',
        'sku' => 'WGT-001',
        'description' => 'Standard Widget',
    ]);
});

it('rejects duplicate SKU for active products with 422', function () {
    Product::factory()->create([
        'sku' => 'WGT-001',
    ]);

    $payload = [
        'name' => 'Widget B',
        'sku' => 'WGT-001',
        'description' => 'Duplicate SKU Widget',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/products', $payload, $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
            'data' => [
                'errors' => [
                    'sku' => [
                        'The sku has already been taken.',
                    ],
                ],
            ],
        ]);
});

it('allows SKU reuse if the previous product with the same SKU was soft deleted', function () {
    $product = Product::factory()->create([
        'sku' => 'REUSE-001',
    ]);

    $product->delete();

    $payload = [
        'name' => 'New Product Same SKU',
        'sku' => 'REUSE-001',
        'description' => 'Reused SKU after soft deletion',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/products', $payload, $this->headers);

    $response->assertStatus(201)
        ->assertJson([
            'ok' => true,
            'code' => 201,
            'message' => 'Product created',
            'data' => [
                'sku' => 'REUSE-001',
            ],
        ]);
});

it('blocks non-admin users from managing products with 403', function () {
    $payload = [
        'name' => 'Unauthorized Product',
        'sku' => 'UNAUTH-001',
    ];

    $response = $this->actingAs($this->orderCreator)
        ->postJson('/api/v1/products', $payload, $this->headers);

    $response->assertStatus(403);

    $product = Product::factory()->create();

    $updateResponse = $this->actingAs($this->orderCreator)
        ->putJson("/api/v1/products/{$product->id}", ['name' => 'Changed Name'], $this->headers);

    $updateResponse->assertStatus(403);

    $deleteResponse = $this->actingAs($this->orderCreator)
        ->deleteJson("/api/v1/products/{$product->id}", [], $this->headers);

    $deleteResponse->assertStatus(403);
});

it('allows admin to list paginated products', function () {
    Product::factory()->count(3)->create();

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/products', $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'totalDataCount' => 3,
        ]);

    expect($response->json('data'))->toHaveCount(3);
});

it('allows admin to fetch single product details', function () {
    $product = Product::factory()->create([
        'name' => 'Detail Product',
        'sku' => 'DTL-001',
        'description' => 'Detail Description',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/products/{$product->id}", $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'data' => [
                'id' => $product->id,
                'name' => 'Detail Product',
                'sku' => 'DTL-001',
                'description' => 'Detail Description',
            ],
        ]);
});

it('allows admin to update name and description', function () {
    $product = Product::factory()->create([
        'name' => 'Widget Original',
        'sku' => 'IMMUTABLE-001',
        'description' => 'Original Description',
    ]);

    $payload = [
        'name' => 'Widget Updated',
        'description' => 'Updated Description',
    ];

    $response = $this->actingAs($this->admin)
        ->putJson("/api/v1/products/{$product->id}", $payload, $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Product updated',
            'data' => [
                'id' => $product->id,
                'name' => 'Widget Updated',
                'sku' => 'IMMUTABLE-001',
                'description' => 'Updated Description',
            ],
        ]);

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Widget Updated',
        'sku' => 'IMMUTABLE-001',
        'description' => 'Updated Description',
    ]);
});

it('rejects attempt to update SKU with 422 validation error', function () {
    $product = Product::factory()->create(['sku' => 'IMMUTABLE-001']);

    $response = $this->actingAs($this->admin)
        ->putJson("/api/v1/products/{$product->id}", ['sku' => 'NEW-SKU-ATTEMPT'], $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
            'data' => [
                'errors' => [
                    'sku' => [
                        'The sku field is prohibited.',
                    ],
                ],
            ],
        ]);
});

it('allows admin to soft delete a product', function () {
    $product = Product::factory()->create([
        'name' => 'Product to Delete',
        'sku' => 'DEL-001',
    ]);

    $response = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/products/{$product->id}", [], $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Product deleted',
        ]);

    $this->assertSoftDeleted('products', [
        'id' => $product->id,
    ]);
});
