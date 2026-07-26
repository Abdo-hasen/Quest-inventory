<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->orderCreator = User::factory()->create(['role' => UserRole::OrderCreator]);
    $this->warehouseOperator = User::factory()->create(['role' => UserRole::WarehouseOperator]);
});

test('admin passes admin-only gates and fails role-specific gates', function () {
    expect(Gate::forUser($this->admin)->allows('manage-products'))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('manage-warehouses'))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('manage-users'))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('adjust-stock'))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('create-orders'))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('manage-reservations'))->toBeFalse();
});

test('order creator passes order-creator gates and fails other role gates', function () {
    expect(Gate::forUser($this->orderCreator)->allows('create-orders'))->toBeTrue()
        ->and(Gate::forUser($this->orderCreator)->allows('view-own-orders'))->toBeTrue()
        ->and(Gate::forUser($this->orderCreator)->allows('manage-products'))->toBeFalse()
        ->and(Gate::forUser($this->orderCreator)->allows('pick-pack-ship'))->toBeFalse();
});

test('warehouse operator passes warehouse-operator gates and fails other role gates', function () {
    expect(Gate::forUser($this->warehouseOperator)->allows('manage-reservations'))->toBeTrue()
        ->and(Gate::forUser($this->warehouseOperator)->allows('pick-pack-ship'))->toBeTrue()
        ->and(Gate::forUser($this->warehouseOperator)->allows('transfer-stock'))->toBeTrue()
        ->and(Gate::forUser($this->warehouseOperator)->allows('manage-products'))->toBeFalse()
        ->and(Gate::forUser($this->warehouseOperator)->allows('create-orders'))->toBeFalse();
});

test('all 3 roles pass view-inventory gate', function () {
    expect(Gate::forUser($this->admin)->allows('view-inventory'))->toBeTrue()
        ->and(Gate::forUser($this->orderCreator)->allows('view-inventory'))->toBeTrue()
        ->and(Gate::forUser($this->warehouseOperator)->allows('view-inventory'))->toBeTrue();
});

test('can middleware blocks unauthorized requests with 403', function () {
    Route::get('/test-admin-only', fn () => response()->json(['ok' => true]))
        ->middleware(['auth:sanctum', 'can:manage-products']);

    $this->actingAs($this->warehouseOperator)
        ->getJson('/test-admin-only')
        ->assertStatus(403);

    $this->actingAs($this->admin)
        ->getJson('/test-admin-only')
        ->assertStatus(200);
});

test('user without a valid role fails all gate checks', function () {
    $userWithoutRole = User::factory()->make(['role' => null]);

    $gates = [
        'manage-products',
        'manage-warehouses',
        'manage-users',
        'adjust-stock',
        'create-orders',
        'view-own-orders',
        'manage-reservations',
        'pick-pack-ship',
        'transfer-stock',
        'view-inventory',
    ];

    foreach ($gates as $gate) {
        expect(Gate::forUser($userWithoutRole)->allows($gate))->toBeFalse();
    }
});
