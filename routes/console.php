<?php

use App\Services\Payments\PaymentReconciliationService;
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
