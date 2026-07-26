<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('warehouse-operator')->name('warehouse-operator.')->group(function () {
    // Warehouse Operator domain routes
});
