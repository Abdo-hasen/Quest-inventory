<?php

declare(strict_types=1);

use App\Core\Enums\ReservationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_line_id')->constrained('order_lines')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_picked')->default(0);
            $table->unsignedInteger('quantity_packed')->default(0);
            $table->unsignedInteger('quantity_shipped')->default(0);
            $table->unsignedInteger('quantity_released')->default(0);
            $table->string('status')->default(ReservationStatus::Open->value)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
