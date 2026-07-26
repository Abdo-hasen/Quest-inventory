<?php

namespace Database\Seeders;

use App\Core\Enums\UserRole;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
                'role' => UserRole::Admin,
            ]
        );

        $creator = User::firstOrCreate(
            ['email' => 'creator@example.com'],
            [
                'name' => 'Order Creator User',
                'password' => bcrypt('password'),
                'role' => UserRole::OrderCreator,
            ]
        );

        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'WH-MAIN'],
            [
                'name' => 'Main Warehouse',
                'address' => '123 Logistics Way',
                'is_active' => true,
            ]
        );

        $product = Product::firstOrCreate(
            ['sku' => 'SKU-SMOKE-1'],
            [
                'name' => 'Smoke Test Product',
                'description' => 'Product for smoke testing',
            ]
        );

        Inventory::firstOrCreate(
            [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
            ],
            [
                'quantity_available' => 50,
                'quantity_reserved' => 0,
                'quantity_picked' => 0,
                'quantity_packed' => 0,
                'quantity_shipped' => 0,
            ]
        );
    }
}
