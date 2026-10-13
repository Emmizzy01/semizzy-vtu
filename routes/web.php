<?php

use App\Http\Controllers\Payments\PaymentFundingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))
    ->name('home');

Route::get('/wallet/funding/return', [PaymentFundingController::class, 'returnFromCheckout'])
    ->name('wallet.funding.return');

Route::middleware('auth')->post('/wallet/funding-intents', [PaymentFundingController::class, 'createIntent'])
    ->middleware('throttle:10,1')
    ->name('wallet.funding-intents.create');