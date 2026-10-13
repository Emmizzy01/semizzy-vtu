<?php

use App\Http\Controllers\Payments\PaymentFundingController;
use App\Http\Controllers\Payments\PaymentRefundController;
use App\Http\Controllers\Admin\StaffAccessController;
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

Route::middleware(['auth', 'can:staff.permissions.manage'])->prefix('admin/staff')->name('admin.staff.')->group(function (): void {
    Route::get('/', [StaffAccessController::class, 'dashboard'])->name('index');
    Route::get('/users', [StaffAccessController::class, 'users'])->middleware('throttle:60,1')->name('users');
    Route::get('/permission-audits', [StaffAccessController::class, 'audits'])->middleware('throttle:30,1')->name('audits');
    Route::post('/users/{user}/permissions', [StaffAccessController::class, 'grant'])->middleware('throttle:20,1')->name('permissions.grant');
    Route::delete('/users/{user}/permissions', [StaffAccessController::class, 'revoke'])->middleware('throttle:20,1')->name('permissions.revoke');
});
