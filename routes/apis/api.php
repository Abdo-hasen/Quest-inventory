<?php

declare(strict_types=1);

use App\Http\Middleware\CheckAppApiKeyMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware(CheckAppApiKeyMiddleware::class)->name('api.')->group(function () {

    Route::prefix('v1')->group(function () {

        include __DIR__.DIRECTORY_SEPARATOR.'V1'.DIRECTORY_SEPARATOR.'auth.php';

        include __DIR__.DIRECTORY_SEPARATOR.'V1'.DIRECTORY_SEPARATOR.'public.php';

        include __DIR__.DIRECTORY_SEPARATOR.'V1'.DIRECTORY_SEPARATOR.'admin.php';

        include __DIR__.DIRECTORY_SEPARATOR.'V1'.DIRECTORY_SEPARATOR.'order_creator.php';

        include __DIR__.DIRECTORY_SEPARATOR.'V1'.DIRECTORY_SEPARATOR.'warehouse_operator.php';

    });

});
