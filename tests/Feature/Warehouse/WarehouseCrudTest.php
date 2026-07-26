<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
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
});

it('allows admin to create a warehouse', function () {
    $payload = [
        'name' => 'Central Warehouse',
        'code' => 'WH-CENTRAL',
        'address' => '789 Main Rd',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/warehouses', $payload, $this->headers);

    $response->assertStatus(201)
        ->assertJson([
            'ok' => true,
            'code' => 201,
            'message' => 'Warehouse created',
            'direct' => null,
            'data' => [
                'name' => 'Central Warehouse',
                'code' => 'WH-CENTRAL',
                'address' => '789 Main Rd',
                'is_active' => true,
            ],
        ]);

    $this->assertDatabaseHas('warehouses', [
        'name' => 'Central Warehouse',
        'code' => 'WH-CENTRAL',
        'address' => '789 Main Rd',
        'is_active' => true,
    ]);
});

it('rejects duplicate warehouse code with 422', function () {
    Warehouse::factory()->create([
        'code' => 'WH-EXISTING',
    ]);

    $payload = [
        'name' => 'New Warehouse',
        'code' => 'WH-EXISTING',
        'address' => '123 New St',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/warehouses', $payload, $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
            'data' => [
                'errors' => [
                    'code' => [
                        'The code has already been taken.',
                    ],
                ],
            ],
        ]);
});

it('blocks non-admin users from creating or updating warehouses with 403', function () {
    $payload = [
        'name' => 'Unauthorized Warehouse',
        'code' => 'WH-UNAUTH',
    ];

    $response = $this->actingAs($this->orderCreator)
        ->postJson('/api/v1/warehouses', $payload, $this->headers);

    $response->assertStatus(403);

    $warehouse = Warehouse::factory()->create();

    $updateResponse = $this->actingAs($this->orderCreator)
        ->putJson("/api/v1/warehouses/{$warehouse->id}", ['name' => 'Changed Name'], $this->headers);

    $updateResponse->assertStatus(403);
});

it('allows admin to update and deactivate a warehouse while enforcing code immutability', function () {
    $warehouse = Warehouse::factory()->create([
        'name' => 'Old Hub',
        'code' => 'WH-ORIGINAL',
        'address' => 'Old Address',
        'is_active' => true,
    ]);

    $payload = [
        'name' => 'North Hub B',
        'code' => 'WH-ATTEMPTED-CHANGE',
        'address' => '456 New Rd',
        'is_active' => false,
    ];

    $response = $this->actingAs($this->admin)
        ->putJson("/api/v1/warehouses/{$warehouse->id}", $payload, $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Warehouse updated',
            'data' => [
                'id' => $warehouse->id,
                'name' => 'North Hub B',
                'code' => 'WH-ORIGINAL',
                'address' => '456 New Rd',
                'is_active' => false,
            ],
        ]);

    $this->assertDatabaseHas('warehouses', [
        'id' => $warehouse->id,
        'name' => 'North Hub B',
        'code' => 'WH-ORIGINAL',
        'address' => '456 New Rd',
        'is_active' => false,
    ]);
});

it('allows admin to fetch single warehouse details', function () {
    $warehouse = Warehouse::factory()->create([
        'name' => 'Detail Warehouse',
        'code' => 'WH-DETAIL',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/warehouses/{$warehouse->id}", $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'data' => [
                'id' => $warehouse->id,
                'name' => 'Detail Warehouse',
                'code' => 'WH-DETAIL',
            ],
        ]);
});

it('allows admin to list all warehouses including inactive ones', function () {
    Warehouse::factory()->create(['name' => 'Active WH', 'is_active' => true]);
    Warehouse::factory()->create(['name' => 'Inactive WH', 'is_active' => false]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/warehouses', $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
        ]);

    expect($response->json('data'))->toHaveCount(2);
});
