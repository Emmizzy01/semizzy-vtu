<?php

use App\Models\User;
use App\Models\UserPermission;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\Payments\PaymentRefundMonitoringService;
use App\Services\Payments\PaymentRefundReconciliationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('semizzy:about', function () {
    $this->info('SEMIZZY VTU application foundation');
})->purpose('Display basic SEMIZZY application information');

Artisan::command('semizzy:permission:grant {email} {permission} {--force}', function () {
    if (! $this->option('force')) {
        $this->error('This changes privileged access. Re-run with --force after verifying the operator and account.');
        return 1;
    }

    $email = strtolower(trim((string) $this->argument('email')));
    $permission = (string) $this->argument('permission');
    $allowedPermissions = ['refunds.approve', 'staff.permissions.manage'];

    if (! in_array($permission, $allowedPermissions, true)) {
        $this->error('Unsupported permission. Allowed values: '.implode(', ', $allowedPermissions));
        return 1;
    }

    $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    if (! $user) {
        $this->error('No user found for that email address.');
        return 1;
    }

    $record = UserPermission::query()->firstOrCreate([
        'user_id' => $user->id,
        'permission' => $permission,
    ]);

    $this->info($record->wasRecentlyCreated
        ? "Granted {$permission} to user ID {$user->id}."
        : "User ID {$user->id} already has {$permission}.");

    return 0;
})->purpose('Grant an allowlisted staff permission from the server CLI');

Artisan::command('semizzy:permission:revoke {email} {permission} {--force}', function () {
    if (! $this->option('force')) {
        $this->error('This changes privileged access. Re-run with --force after verifying the operator and account.');
        return 1;
    }

    $email = strtolower(trim((string) $this->argument('email')));
    $permission = (string) $this->argument('permission');
    $allowedPermissions = ['refunds.approve', 'staff.permissions.manage'];

    if (! in_array($permission, $allowedPermissions, true)) {
        $this->error('Unsupported permission. Allowed values: '.implode(', ', $allowedPermissions));
        return 1;
    }

    $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    if (! $user) {
        $this->error('No user found for that email address.');
        return 1;
    }

    $deleted = UserPermission::query()
        ->where('user_id', $user->id)
        ->where('permission', $permission)
        ->delete();

    $this->info($deleted > 0
        ? "Revoked {$permission} from user ID {$user->id}."
        : "User ID {$user->id} did not have {$permission}.");

    return 0;
})->purpose('Revoke an allowlisted staff permission from the server CLI');

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
