<?php

declare(strict_types=1);

use App\Http\Controllers\API\Order\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:create-orders'])->group(function () {
    Route::post('orders', [OrderController::class, 'store'])
        ->middleware('idempotency')
        ->name('orders.store');
});

Route::middleware(['auth:sanctum', 'can:view-own-orders'])->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
});
