<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
final class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'type' => MovementType::Adjustment,
            'quantity_delta' => 10,
            'reason' => 'Test movement',
            'actor_id' => null,
            'related_order_id' => null,
            'related_reservation_id' => null,
            'created_at' => now(),
        ];
    }
}
