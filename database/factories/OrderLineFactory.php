<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrderLine;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
final class OrderLineFactory extends Factory
{
    protected $model = OrderLine::class;

    public function definition(): array
    {
        return [
            'sales_order_id' => SalesOrder::factory(),
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity' => 1,
        ];
    }
}
