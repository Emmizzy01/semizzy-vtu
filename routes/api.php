<?php

use App\Http\Controllers\Payments\PaymentFundingController;
use Illuminate\Support\Facades\Route;

Route::post('/payments/webhooks/{provider}', [PaymentFundingController::class, 'webhook'])
    ->middleware('throttle:60,1')
    ->name('payments.webhooks.receive');