<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Enums\OrderStatus;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrder>
 */
final class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => OrderStatus::Open,
        ];
    }
}
