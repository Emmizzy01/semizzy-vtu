<?php

use App\Http\Controllers\Payments\PaymentFundingController;
use App\Http\Controllers\Payments\PaymentRefundController;
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

Route::middleware(['auth', 'throttle:10,1'])->post('/wallet/refund-requests', [PaymentRefundController::class, 'requestRefund'])
    ->name('wallet.refund-requests.create');

Route::middleware(['auth', 'throttle:30,1'])->get('/wallet/refund-requests/{refund}', [PaymentRefundController::class, 'show'])
    ->name('wallet.refund-requests.show');

Route::middleware(['auth', 'can:refunds.approve'])->get('/admin/refunds', [PaymentRefundController::class, 'dashboard'])
    ->name('admin.refunds.dashboard');

Route::middleware(['auth', 'can:refunds.approve', 'throttle:30,1'])->get('/admin/payment-refunds', [PaymentRefundController::class, 'index'])
    ->name('admin.payment-refunds.index');

Route::middleware(['auth', 'can:refunds.approve', 'throttle:20,1'])->post('/admin/payment-refunds/{refund}/approve', [PaymentRefundController::class, 'approveRefund'])
    ->name('admin.payment-refunds.approve');