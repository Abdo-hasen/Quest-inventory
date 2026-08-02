<?php

use App\Core\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->headers = [
        'Accept' => 'application/json',
        'X-API-KEY' => 'secret_app_key_123',
    ];
});

it('logs in successfully and returns token and role', function () {
    $user = User::factory()->create([
        'email' => 'operator@warehouse.com',
        'password' => bcrypt('secret123'),
        'role' => UserRole::WarehouseOperator,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'operator@warehouse.com',
        'password' => 'secret123',
    ], $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Login successful',
            'direct' => null,
            'data' => [
                'role' => 'warehouse_operator',
            ],
        ]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});

it('returns 401 unauthorized for invalid password', function () {
    User::factory()->create([
        'email' => 'operator@warehouse.com',
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'operator@warehouse.com',
        'password' => 'wrongpassword',
    ], $this->headers);

    $response->assertStatus(401)
        ->assertJson([
            'ok' => false,
            'code' => 401,
            'message' => 'Invalid credentials',
            'direct' => null,
            'data' => null,
        ]);
});

it('returns 422 unprocessable content when validation fails', function () {
    $response = $this->postJson('/api/v1/auth/login', [], $this->headers);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'code' => 422,
        ]);
});

it('returns 401 unauthorized for non-existent email', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'nonexistent@warehouse.com',
        'password' => 'secret123',
    ], $this->headers);

    $response->assertStatus(401)
        ->assertJson([
            'ok' => false,
            'code' => 401,
            'message' => 'Invalid credentials',
            'direct' => null,
            'data' => null,
        ]);
});

it('returns 429 too many requests when login rate limit is exceeded', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@warehouse.com',
            'password' => 'wrong',
        ], $this->headers);
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@warehouse.com',
        'password' => 'wrong',
    ], $this->headers);

    $response->assertStatus(429);
});
