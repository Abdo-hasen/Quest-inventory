<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('admin')->name('admin.')->group(function () {
    // Admin domain routes
});
