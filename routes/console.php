<?php

use App\Models\User;
use App\Models\UserPermission;
use App\Models\StaffPermissionAudit;
use App\Models\EducationOrder;
use App\Models\ScheduledServicePurchase;
use App\Services\Vtu\VtuOrderService;
use Illuminate\Support\Facades\DB;
use App\Services\Education\EducationOrderCoordinator;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\Payments\PaymentRefundMonitoringService;
use App\Services\Payments\PaymentRefundReconciliationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('semizzy:about', function () {
    $this->info('SEMIZZY VTU application foundation');
})->purpose('Display basic SEMIZZY application information');


Artisan::command('semizzy:bootstrap-admin {email} {--force : Confirm this one-time first-admin setup}', function () {
    if (! $this->option('force')) {
        $this->error('No changes made. Verify the target account, then rerun with --force.');
        return 1;
    }

    $email = strtolower(trim((string) $this->argument('email')));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Provide a valid email address for the intended administrator.');
        return 1;
    }

    $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    if (! $user) {
        $this->error('No existing account matches that email address. No changes were made.');
        return 1;
    }

    $result = \Illuminate\Support\Facades\DB::transaction(function () use ($user): array {
        $privilegedPermissions = ['staff.permissions.manage', 'refunds.approve'];
        $existingAdminAccess = UserPermission::query()
            ->whereIn('permission', $privilegedPermissions)
            ->lockForUpdate()
            ->exists();

        if ($existingAdminAccess) {
            return ['created' => false, 'reason' => 'already_bootstrapped'];
        }

        UserPermission::query()->create([
            'user_id' => $user->id,
            'permission' => 'staff.permissions.manage',
            'granted_by_user_id' => null,
        ]);

        StaffPermissionAudit::query()->create([
            'actor_user_id' => null,
            'target_user_id' => $user->id,
            'permission' => 'staff.permissions.manage',
            'action' => 'granted',
            'reason' => 'One-time first administrator bootstrap via server CLI; operator-confirmed target email.',
        ]);

        return ['created' => true, 'reason' => null];
    });

    if (! $result['created']) {
        $this->error('First-admin setup is locked: privileged access already exists. No changes were made.');
        return 1;
    }

    $this->info("First administrator access granted to user ID {$user->id}.");
    $this->warn('Audit recorded with Server CLI as the actor. Keep server CLI access restricted.');

    return 0;
})->purpose('Safely bootstrap the first Semizzy administrator exactly once');

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

    if ($record->wasRecentlyCreated) {
        StaffPermissionAudit::query()->create([
            'actor_user_id' => null,
            'target_user_id' => $user->id,
            'permission' => $permission,
            'action' => 'granted',
            'reason' => 'Server CLI provisioning; authorized operator verified the account.',
        ]);
    }

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

    if ($deleted > 0) {
        StaffPermissionAudit::query()->create([
            'actor_user_id' => null,
            'target_user_id' => $user->id,
            'permission' => $permission,
            'action' => 'revoked',
            'reason' => 'Server CLI revocation; authorized operator verified the account.',
        ]);
    }

    $this->info($deleted > 0
        ? "Revoked {$permission} from user ID {$user->id}."
        : "User ID {$user->id} did not have {$permission}.");

    return 0;
})->purpose('Revoke an allowlisted staff permission from the server CLI');

Artisan::command('vtu:run-scheduled-purchases {--limit=100}', function (VtuOrderService $orders) {
    $limit = (int) $this->option('limit');
    if ($limit < 1 || $limit > 500) {
        $this->error('The limit must be between 1 and 500.');
        return 1;
    }

    // Recover abandoned claims conservatively. A process may have died after
    // submitting to the provider, so stale processing rows are paused for review,
    // never returned to the active queue automatically.
    $staleIds = ScheduledServicePurchase::query()
        ->where('status', 'processing')
        ->where('updated_at', '<=', now()->subMinutes(30))
        ->orderBy('updated_at')
        ->limit($limit)
        ->pluck('id');

    foreach ($staleIds as $staleId) {
        DB::transaction(function () use ($staleId): void {
            $stale = ScheduledServicePurchase::query()->whereKey($staleId)->lockForUpdate()->first();
            if (!$stale || $stale->status !== 'processing' || $stale->updated_at->greaterThan(now()->subMinutes(30))) {
                return;
            }

            $stale->update([
                'status' => 'paused',
                'last_run_at' => $stale->last_run_at ?? now(),
                'last_result' => 'unknown',
                'last_error' => 'A scheduled run was interrupted and paused after 30 minutes. Check the stable scheduled idempotency reference and reconcile the existing order before any manual resume.',
            ]);
        });
    }

    $ids = ScheduledServicePurchase::query()
        ->where('status', 'active')
        ->where('next_run_at', '<=', now())
        ->orderBy('next_run_at')
        ->limit($limit)
        ->pluck('id');

    $processed = 0;
    $paused = 0;
    foreach ($ids as $id) {
        $schedule = DB::transaction(function () use ($id) {
            $row = ScheduledServicePurchase::query()->whereKey($id)->lockForUpdate()->first();
            if (!$row || $row->status !== 'active' || $row->next_run_at->isFuture()) return null;
            $row->update(['status' => 'processing', 'last_error' => null]);
            return $row;
        });
        if (!$schedule) continue;

        $dueAt = $schedule->next_run_at->copy();
        try {
            $order = $orders->purchase(
                $schedule->user,
                $schedule->product_code,
                $schedule->recipient,
                'scheduled:'.$schedule->id.':'.$dueAt->timestamp,
                $schedule->service_details ?? [],
                $schedule->amount_minor,
            );
            $next = match ($schedule->frequency) {
                'daily' => $dueAt->copy()->addDay(),
                'weekly' => $dueAt->copy()->addWeek(),
                'monthly' => $dueAt->copy()->addMonthNoOverflow(),
                default => null,
            };
            // Do not replay missed periods in a burst after downtime.
            while ($next && $next->lessThanOrEqualTo(now())) {
                $next = match ($schedule->frequency) {
                    'daily' => $next->addDay(),
                    'weekly' => $next->addWeek(),
                    'monthly' => $next->addMonthNoOverflow(),
                    default => null,
                };
            }
            $orderStatus = (string) $order->status;
            $confirmedFinal = in_array($orderStatus, ['successful', 'failed', 'cancelled'], true);
            // Finalize under a fresh row lock. A concurrent stale-claim recovery
            // may have paused this schedule while the provider request was in flight;
            // never overwrite that safety pause or reactivate it automatically.
            $finalized = DB::transaction(function () use ($schedule, $next, $confirmedFinal, $dueAt, $order, $orderStatus): bool {
                $current = ScheduledServicePurchase::query()->whereKey($schedule->id)->lockForUpdate()->first();
                if (!$current || $current->status !== 'processing') {
                    if ($current) {
                        $current->update([
                            'last_run_at' => now(),
                            'last_order_public_id' => $order->public_id,
                            'last_result' => $orderStatus,
                            'last_error' => 'The provider returned after this schedule was paused. Keep it paused and reconcile the recorded order before resuming.',
                        ]);
                    }
                    return false;
                }

                $current->update([
                    'status' => ($next && $confirmedFinal) ? 'active' : 'paused',
                    'last_run_at' => now(),
                    'next_run_at' => ($next && $confirmedFinal) ? $next : $dueAt,
                    'last_order_public_id' => $order->public_id,
                    'last_result' => $orderStatus,
                    'last_error' => $confirmedFinal ? null : 'The provider outcome is not final. This schedule is paused until the existing order is reconciled.',
                ]);
                return true;
            });
            if ($finalized) {
                $processed++;
                if (!$confirmedFinal) $paused++;
            } else {
                $paused++;
            }
        } catch (\Throwable $exception) {
            report($exception);
            // Fail closed. Preserve any pause made by recovery or an operator.
            DB::transaction(function () use ($schedule): void {
                $current = ScheduledServicePurchase::query()->whereKey($schedule->id)->lockForUpdate()->first();
                if (!$current) return;
                $current->update([
                    'status' => $current->status === 'processing' ? 'paused' : $current->status,
                    'last_run_at' => now(),
                    'last_result' => 'unknown',
                    'last_error' => 'Scheduled run paused after an uncertain or rejected result. Review the order before resuming.',
                ]);
            });
            $paused++;
        }
    }

    $this->table(['Processed', 'Paused for review'], [[$processed, $paused]]);
    return 0;
})->purpose('Run due recurring purchases through the existing VTU order and wallet-ledger safeguards');

Schedule::command('vtu:run-scheduled-purchases --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

// Shared/cPanel hosting may not provide a persistent queue worker. Drain the
// durable database queue in bounded scheduled windows; supervised queue:work
// processes may also run. Bulk items are claimed atomically and uncertain items
// are never blindly resubmitted.
Schedule::command('queue:work database --stop-when-empty --timeout=60 --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();

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


Artisan::command('vtu:process-provider-webhooks {--limit=100}', function (
    \App\Services\Vtu\VtuProviderWebhookService $webhooks,
) {
    $limit = (int) $this->option('limit');
    if ($limit < 1 || $limit > 500) {
        $this->error('The limit must be between 1 and 500.');
        return 1;
    }

    $stats = $webhooks->process($limit);
    $this->table(['Checked', 'Processed', 'Awaiting order match', 'Ignored'], [[
        $stats['checked'], $stats['processed'], $stats['unmatched'], $stats['ignored'],
    ]]);

    return 0;
})->purpose('Process verified provider webhooks idempotently against recorded VTU order ownership');

Schedule::command('vtu:process-provider-webhooks --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('vtu:reconcile-orders {--limit=100}', function (
    \App\Services\Vtu\VtuOrderService $orders,
) {
    $limit = (int) $this->option('limit');
    if ($limit < 1 || $limit > 500) {
        $this->error('The limit must be between 1 and 500.');

        return 1;
    }

    $configured = config('services.vtu.driver') === 'vtpass'
        ? (config('services.vtu.vtpass.base_url') && config('services.vtu.vtpass.api_key') && config('services.vtu.vtpass.secret_key'))
        : (config('services.vtu.endpoint') && config('services.vtu.token'));

    if (! $configured) {
        $this->warn('VTU provider is not configured; no orders were queried.');
        return 0;
    }

    $candidates = \App\Models\VtuOrder::query()
        ->whereIn('status', ['processing', 'awaiting_reconciliation'])
        ->whereNotNull('dispatch_started_at')
        ->where('dispatch_started_at', '<=', now()->subMinute())
        // Back off unresolved orders based on their last update to avoid querying
        // the provider every minute while it continues to report pending.
        ->where('updated_at', '<=', now()->subMinutes(5))
        ->orderBy('updated_at')
        ->orderBy('dispatch_started_at')
        ->limit($limit)
        ->get();

    $changed = 0;
    foreach ($candidates as $order) {
        $before = $order->status;
        try {
            $after = $orders->reconcile($order);
            if ($after->status !== $before) {
                $changed++;
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    $this->table(
        ['Checked', 'Status changed', 'Still unresolved'],
        [[$candidates->count(), $changed, $candidates->count() - $changed]],
    );

    return 0;
})->purpose('Reconcile pending or ambiguous VTU provider orders');

Schedule::command('vtu:reconcile-orders --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('education:reconcile-orders {--limit=100}', function (
    EducationOrderCoordinator $coordinator,
) {
    $limit = (int) $this->option('limit');
    if ($limit < 1 || $limit > 500) {
        $this->error('The limit must be between 1 and 500.');

        return 1;
    }

    // Only reconcile orders that were actually dispatched and have remained
    // unresolved long enough to avoid repeated provider lookups during normal processing.
    $candidates = EducationOrder::query()
        ->whereIn('status', ['processing', 'pending', 'unknown'])
        ->whereNotNull('dispatch_started_at')
        ->where('dispatch_started_at', '<=', now()->subMinute())
        ->where('updated_at', '<=', now()->subMinutes(5))
        ->orderBy('updated_at')
        ->orderBy('dispatch_started_at')
        ->limit($limit)
        ->get();

    $changed = 0;
    foreach ($candidates as $order) {
        $before = $order->status;
        try {
            // The coordinator uses the provider identity persisted on the order;
            // it never resubmits or routes the purchase to another provider here.
            $after = $coordinator->reconcile($order);
            if ($after->status !== $before) {
                $changed++;
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    $this->table(
        ['Checked', 'Status changed', 'Still unresolved'],
        [[$candidates->count(), $changed, $candidates->count() - $changed]],
    );

    return 0;
})->purpose('Reconcile pending or ambiguous education provider orders without resubmitting purchases');

Schedule::command('education:reconcile-orders --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('vtu:monitor-orders', function (\App\Services\Vtu\VtuOrderMonitoringService $monitoring) {
    $stats = $monitoring->monitor();
    $this->table(['Threshold minutes', 'Stuck total', 'Processing', 'Awaiting reconciliation', 'Alerts sent'], [[
        $stats['threshold_minutes'],
        $stats['stuck_total'],
        $stats['processing'],
        $stats['awaiting_reconciliation'],
        $stats['alerts_sent'],
    ]]);

    return 0;
})->purpose('Alert operators about old unresolved VTU orders without retrying or refunding them');

Schedule::command('vtu:monitor-orders')
    ->everyFifteenMinutes()
    ->withoutOverlapping();


Artisan::command('vtu:catalog-sync {--apply : Save the verified provider catalogue to the database}', function (
    \App\Services\Vtu\VtpassCatalogService $catalog,
) {
    try {
        $products = $catalog->fetchProducts();
    } catch (\Throwable $exception) {
        report($exception);
        $this->error('Catalogue retrieval failed. No catalogue changes were applied.');

        return 1;
    }

    $this->info('Verified catalogue preview: '.count($products).' products found.');
    $this->table(
        ['Type', 'Network', 'Product', 'Price (NGN)', 'Provider code'],
        array_map(fn (array $product): array => [
            $product['service_type'],
            $product['network'],
            $product['name'],
            number_format($product['price_minor'] / 100, 2),
            $product['code'],
        ], $products),
    );

    if (! $this->option('apply')) {
        $this->warn('Preview only: database unchanged. Add --apply only after reviewing the catalogue.');
        return 0;
    }

    $count = $catalog->applyProducts($products);
    $this->info("Saved {$count} catalogue products. No purchase was submitted.");

    return 0;
})->purpose('Preview VTpass products safely; persist only when --apply is explicitly supplied');


Artisan::command('semizzy:schema-audit', function () {
    // Read-only audit for the production schema drift reported on 2026-10-18.
    // This command deliberately does not run migrations or alter database state.
    $required = [
        'vtu_products' => [
            'provider_key',
            'provider_product_code',
            'provider_cost_minor',
            'data_type',
            'validity',
            'pricing_by_user_category',
            'commission_by_user_category',
        ],
        'streaming_subscription_products' => [
            'id', 'platform', 'name', 'code', 'price_minor', 'is_active', 'purchase_approved',
        ],
        'vtu_website_requests' => [
            'id', 'public_id', 'user_id', 'request_type', 'status', 'description',
        ],
        'vtu_website_request_events' => [
            'id', 'request_id', 'action', 'created_at',
        ],
        'platform_feature_controls' => ['feature_key', 'is_enabled', 'is_implemented'],
        'integration_settings' => ['key', 'value'],
    ];

    $missing = [];
    foreach ($required as $table => $columns) {
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
            $missing[] = [$table, '(table missing)'];
            continue;
        }

        foreach ($columns as $column) {
            if (!\Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                $missing[] = [$table, $column];
            }
        }
    }

    if ($missing !== []) {
        $this->error('Schema audit found missing database objects. No changes were made.');
        $this->table(['Table', 'Missing object'], $missing);
        $this->line('Review migration status and schema history before applying any migration.');
        return 1;
    }

    $this->info('Schema audit passed: all required tables and columns are present. No changes were made.');
    return 0;
})->purpose('Read-only audit of required production database tables and columns');
