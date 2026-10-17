<?php

use App\Http\Controllers\Payments\PaymentFundingController;
use App\Http\Controllers\Vtu\VtuProviderWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/payments/webhooks/{provider}', [PaymentFundingController::class, 'webhook'])
    ->middleware('throttle:60,1')
    ->name('payments.webhooks.receive');
Route::post('/vtu/webhooks/{provider}', VtuProviderWebhookController::class)
    ->whereIn('provider', ['sogo', 'dataway'])
    ->middleware('throttle:60,1')
    ->name('vtu.provider-webhooks.receive');
