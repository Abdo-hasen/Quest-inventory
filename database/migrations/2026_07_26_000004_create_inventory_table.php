<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->unsignedInteger('quantity_available')->default(0);
            $table->unsignedInteger('quantity_reserved')->default(0);
            $table->unsignedInteger('quantity_picked')->default(0);
            $table->unsignedInteger('quantity_packed')->default(0);
            $table->unsignedInteger('quantity_shipped')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE inventory ADD CONSTRAINT chk_qty_available CHECK (quantity_available >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
