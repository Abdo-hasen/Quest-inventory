<?php

declare(strict_types=1);

use App\Http\Controllers\API\Order\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:create-orders'])->group(function () {
    Route::post('orders', [OrderController::class, 'store'])
        ->middleware('idempotency')
        ->name('orders.store');
});
