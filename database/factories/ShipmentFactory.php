<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Enums\ShipmentStatus;
use App\Models\Reservation;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
final class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'quantity' => 1,
            'status' => ShipmentStatus::Pending,
        ];
    }
}
