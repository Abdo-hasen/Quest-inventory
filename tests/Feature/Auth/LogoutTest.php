<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->headers = [
        'Accept' => 'application/json',
        'X-API-KEY' => 'secret_app_key_123',
    ];
});

it('revokes current token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-token');

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/auth/logout', [], $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'message' => 'Logged out successfully',
            'direct' => null,
            'data' => [],
        ]);

    expect($user->tokens()->count())->toBe(0);
});

it('returns 401 unauthorized when logging out unauthenticated', function () {
    $response = $this->postJson('/api/v1/auth/logout', [], $this->headers);

    $response->assertStatus(401)
        ->assertJson([
            'ok' => false,
            'code' => 401,
        ]);
});
