<?php

declare(strict_types=1);

use App\Http\Controllers\API\Inventory\InventoryController;
use App\Http\Controllers\API\Product\ProductController;
use App\Http\Controllers\API\Reservation\ReservationController;
use App\Http\Controllers\API\Warehouse\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:manage-products'])->group(function () {
    Route::apiResource('products', ProductController::class);
});

Route::middleware(['auth:sanctum', 'can:manage-warehouses'])->group(function () {
    Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'store', 'show', 'update']);
});

Route::middleware(['auth:sanctum', 'can:adjust-stock'])->group(function () {
    Route::post('inventory/adjust', [InventoryController::class, 'adjust']);
    Route::get('reservations/{reservation}/history', [ReservationController::class, 'history'])->name('reservations.history');
});

Route::middleware(['auth:sanctum', 'can:view-inventory'])->group(function () {
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
});
