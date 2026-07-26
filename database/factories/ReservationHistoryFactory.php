<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationHistory>
 */
final class ReservationHistoryFactory extends Factory
{
    protected $model = ReservationHistory::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'from_status' => ReservationStatus::Open,
            'to_status' => ReservationStatus::Released,
            'quantity_affected' => 1,
            'actor_id' => User::factory(),
            'notes' => 'Factory history entry',
            'created_at' => now(),
        ];
    }
}
