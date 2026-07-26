<?php

declare(strict_types=1);

use App\Http\Controllers\API\Reservation\ReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:manage-reservations'])->group(function () {
    Route::post('reservations/{reservation}/release', [ReservationController::class, 'release'])->name('reservations.release');
    Route::patch('orders/{order}/lines/{line}', [ReservationController::class, 'partialCancel'])->name('orders.lines.update');
});
