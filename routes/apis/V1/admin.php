<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:manage-products'])->prefix('admin')->name('admin.')->group(function () {
    // Admin domain routes
});
