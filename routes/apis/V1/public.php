<?php

declare(strict_types=1);

use App\Http\Controllers\API\Webhook\ShippingWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/shipping', [ShippingWebhookController::class, 'handle'])->name('webhooks.shipping');
