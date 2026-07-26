<?php

declare(strict_types=1);

use App\Core\Enums\ReservationStatus;
use App\Core\Enums\UserRole;
use App\Models\Reservation;
use App\Models\ReservationHistory;
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
        'name' => 'Admin User',
    ]);

    $this->operator = User::factory()->create([
        'role' => UserRole::WarehouseOperator,
        'name' => 'John Operator',
    ]);

    $this->reservation = Reservation::factory()->create([
        'status' => ReservationStatus::Packed,
    ]);

    $this->history1 = ReservationHistory::factory()->create([
        'reservation_id' => $this->reservation->id,
        'from_status' => null,
        'to_status' => ReservationStatus::Open,
        'quantity_affected' => 5,
        'actor_id' => null,
        'created_at' => now()->subMinutes(30),
    ]);

    $this->history2 = ReservationHistory::factory()->create([
        'reservation_id' => $this->reservation->id,
        'from_status' => ReservationStatus::Open,
        'to_status' => ReservationStatus::Picked,
        'quantity_affected' => 3,
        'actor_id' => $this->operator->id,
        'created_at' => now()->subMinutes(20),
    ]);

    $this->history3 = ReservationHistory::factory()->create([
        'reservation_id' => $this->reservation->id,
        'from_status' => ReservationStatus::Picked,
        'to_status' => ReservationStatus::Packed,
        'quantity_affected' => 3,
        'actor_id' => $this->operator->id,
        'created_at' => now()->subMinutes(10),
    ]);
});

test('admin can fetch reservation history trail in ascending chronological order', function () {
    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/reservations/{$this->reservation->id}/history", $this->headers);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'code' => 200,
            'data' => [
                'reservation_id' => $this->reservation->id,
            ],
        ]);

    $history = $response->json('data.history');
    expect($history)->toHaveCount(3);

    expect($history[0]['from_status'])->toBeNull()
        ->and($history[0]['to_status'])->toBe('open')
        ->and($history[0]['quantity_affected'])->toBe(5)
        ->and($history[0]['actor'])->toBe('System');

    expect($history[1]['from_status'])->toBe('open')
        ->and($history[1]['to_status'])->toBe('picked')
        ->and($history[1]['actor'])->toBe('John Operator');

    expect($history[2]['from_status'])->toBe('picked')
        ->and($history[2]['to_status'])->toBe('packed')
        ->and($history[2]['actor'])->toBe('John Operator');
});

test('non-admin user cannot access reservation history and receives 403', function () {
    $response = $this->actingAs($this->operator)
        ->getJson("/api/v1/reservations/{$this->reservation->id}/history", $this->headers);

    $response->assertStatus(403);
});

test('fetching history for non-existent reservation returns 404', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/reservations/999999/history', $this->headers);

    $response->assertStatus(404)
        ->assertJson([
            'ok' => false,
            'code' => 404,
        ]);
});
