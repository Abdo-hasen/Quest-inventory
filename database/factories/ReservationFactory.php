<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Enums\ReservationStatus;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
final class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        return [
            'order_line_id' => OrderLine::factory(),
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity' => 1,
            'quantity_picked' => 0,
            'quantity_packed' => 0,
            'quantity_shipped' => 0,
            'quantity_released' => 0,
            'status' => ReservationStatus::Open,
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
