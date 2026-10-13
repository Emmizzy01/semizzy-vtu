<?php

use App\Http\Controllers\Payments\PaymentFundingController;\nuse App\Http\Controllers\Payments\PaymentRefundController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))
    ->name('home');

Route::get('/wallet/funding/return', [PaymentFundingController::class, 'returnFromCheckout'])
    ->middleware('throttle:30,1')
    ->name('wallet.funding.return');

Route::middleware('auth')->post('/wallet/funding-intents', [PaymentFundingController::class, 'createIntent'])
    ->middleware('throttle:10,1')
    ->name('wallet.funding-intents.create');