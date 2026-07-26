<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('order-creator')->name('order-creator.')->group(function () {
    // Order Creator domain routes
});
