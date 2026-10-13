<?php

use App\Services\Payments\PaymentReconciliationService;
use App\Services\Payments\PaymentRefundMonitoringService;
use App\Services\Payments\PaymentRefundReconciliationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('semizzy:about', function () {
    $this->info('SEMIZZY VTU application foundation');
})->purpose('Display basic SEMIZZY application information');

Artisan::command('payments:reconcile-paystack {--limit=100}', function (PaymentReconciliationService $reconciliation) {
    $limit = (int) $this->option('limit');
    if ($limit < 1 || $limit > 500) {
        $this->error('The limit must be between 1 and 500.');

        return 1;
    }

    $stats = $reconciliation->reconcile($limit);
    $this->table(['Checked', 'Credited', 'Failed', 'Exceptions', 'Unavailable'], [[
        $stats['checked'],
        $stats['credited'],
        $stats['failed'],
        $stats['exceptions'],
        $stats['unavailable'],
    ]]);

    return 0;
})->purpose('Reconcile aged Paystack payment intents using provider verification');

Schedule::command('payments:reconcile-paystack --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();


Artisan::command('payments:monitor-refunds', function (PaymentRefundMonitoringService $monitoring) {
    $stats = $monitoring->monitor();
    $this->table(['Stale', 'Processing', 'Provider pending', 'Needs review', 'Threshold minutes', 'Alerts sent'], [[
        $stats['stuck_total'],
        $stats['processing'],
        $stats['provider_pending'],
        $stats['review_required'],
        $stats['threshold_minutes'],
        $stats['alerts_sent'],
    ]]);

    return 0;
})->purpose('Alert operators about refund requests that have stopped progressing');

Artisan::command('payments:reconcile-refunds {--limit=100}', function (
    PaymentRefundReconciliationService $reconciliation,
    PaymentRefundMonitoringService $monitoring,
) {
    $limit = (int) $this->option('limit');
    if ($limit < 1 || $limit > 500) {
        $this->error('The limit must be between 1 and 500.');

        return 1;
    }

    $reconciled = $reconciliation->reconcile($limit);
    $alerts = $monitoring->monitor();
    $this->table(
        ['Checked', 'Reconciled', 'Mismatched', 'Unavailable', 'Skipped', 'Stale alerts'],
        [[
            $reconciled['checked'],
            $reconciled['reconciled'],
            $reconciled['mismatched'],
            $reconciled['unavailable'],
            $reconciled['skipped'],
            $alerts['stuck_total'],
        ]],
    );

    return 0;
})->purpose('Reconcile stale Paystack refunds and alert on unresolved cases');

Schedule::command('payments:reconcile-refunds --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();
