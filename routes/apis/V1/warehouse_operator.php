<?php

declare(strict_types=1);

use App\Http\Controllers\API\Inventory\InventoryController;
use App\Http\Controllers\API\Reservation\ReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:manage-reservations'])->group(function () {
    Route::get('inventory/{product}/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
    Route::post('inventory/transfer', [InventoryController::class, 'transfer'])->name('inventory.transfer');
    Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
    Route::post('reservations/{reservation}/release', [ReservationController::class, 'release'])->name('reservations.release');
    Route::post('reservations/{reservation}/pick', [ReservationController::class, 'pick'])->name('reservations.pick');
    Route::post('reservations/{reservation}/pack', [ReservationController::class, 'pack'])->name('reservations.pack');
    Route::patch('orders/{order}/lines/{line}', [ReservationController::class, 'partialCancel'])->name('orders.lines.update');
});
