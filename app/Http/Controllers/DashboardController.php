<?php

namespace App\Http\Controllers;

use App\Models\ManualDeposit;
use App\Models\PaymentIntent;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\VtuOrder;
use App\Models\VtuProviderAttempt;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DashboardController extends Controller
{
    public function customer(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($this->hasAdminAccess($user->id)) {
            return redirect()->route('admin.dashboard');
        }

        return $this->renderCustomerDashboard($request);
    }

    public function customerPreview(Request $request): InertiaResponse
    {
        abort_unless($request->user(), 401);

        // Allows staff to inspect the customer interface for their own account.
        // This does not grant access to another customer's data.
        return $this->renderCustomerDashboard($request);
    }

    public function admin(Request $request, IntegrationSettings $settings): InertiaResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $canManageStaff = UserPermission::query()
            ->where('user_id', $user->id)
            ->where('permission', 'staff.permissions.manage')
            ->exists();
        $canApproveRefunds = UserPermission::query()
            ->where('user_id', $user->id)
            ->where('permission', 'refunds.approve')
            ->exists();

        $newUsersToday = User::query()->whereDate('created_at', now()->toDateString())->count();
        $newUsersLastSevenDays = User::query()->where('created_at', '>=', now()->subDays(7))->count();
        $recentUserColumns = ['id', 'name', 'kyc_status', 'created_at'];
        if (Schema::hasColumn('users', 'mobile_number')) {
            $recentUserColumns[] = 'mobile_number';
        }
        $recentUsers = $canManageStaff
            ? User::query()->where('created_at', '>=', now()->subDays(7))->latest('created_at')->limit(3)
                ->get($recentUserColumns)
                ->map(function (User $recentUser): array {
                    $digits = preg_replace('/\\D+/', '', (string) ($recentUser->mobile_number ?? '')) ?? '';
                    $maskedPhone = $digits === '' ? 'Phone not provided' : substr($digits, 0, min(4, strlen($digits))) . ' •• ' . substr($digits, -2);
                    return [
                        'id' => (int) $recentUser->id,
                        'name' => (string) $recentUser->name,
                        'phone' => $maskedPhone,
                        'kycStatus' => (string) ($recentUser->kyc_status ?? 'unverified'),
                        'createdAt' => $recentUser->created_at?->toISOString(),
                    ];
                })->values()
            : [];

        // Provider telemetry is based on persisted provider attempts from the last 24 hours.
        // Only definitive success/failure outcomes contribute to the success-rate denominator.
        $providerAttempts = VtuProviderAttempt::query()
            ->where('started_at', '>=', now()->subDay())
            ->get(['provider', 'outcome', 'started_at', 'finished_at']);
        $providerStats = $providerAttempts->groupBy('provider')->map(function ($attempts): array {
            $successful = $attempts->where('outcome', 'success')->count();
            $failed = $attempts->where('outcome', 'failed')->count();
            $resolved = $successful + $failed;
            $durations = $attempts->filter(fn ($attempt): bool => $attempt->finished_at !== null && $attempt->started_at !== null)
                ->map(fn ($attempt): int => max(0, $attempt->finished_at->diffInSeconds($attempt->started_at, true)));

            return [
                'total' => $attempts->count(),
                'successful' => $successful,
                'failed' => $failed,
                'successRate' => $resolved > 0 ? round(($successful / $resolved) * 100, 1) : null,
                'averageResponseSeconds' => $durations->isNotEmpty() ? round($durations->avg(), 1) : null,
            ];
        });
        $providerPerformance = $providerStats->all();
        $resolvedProviderAttempts = $providerAttempts->whereIn('outcome', ['success', 'failed'])->count();
        $successfulProviderAttempts = $providerAttempts->where('outcome', 'success')->count();
        $apiSuccessRate = $resolvedProviderAttempts > 0
            ? round(($successfulProviderAttempts / $resolvedProviderAttempts) * 100, 1)
            : null;

        // This is explicitly successful VTU purchase value over 30 days, not total platform revenue.
        // A business-defined high-value threshold and cross-service spend report are not yet configured.
        $topVtuSpenders = $canManageStaff
            ? VtuOrder::query()
                ->join('users', 'users.id', '=', 'vtu_orders.user_id')
                ->where('vtu_orders.status', 'successful')
                ->where('vtu_orders.created_at', '>=', now()->subDays(30))
                ->groupBy('vtu_orders.user_id', 'users.name')
                ->orderByRaw('SUM(vtu_orders.amount_minor) DESC')
                ->limit(5)
                ->get([
                    'vtu_orders.user_id as id',
                    'users.name as name',
                ])
                ->map(function ($spender): array {
                    $spendMinor = (int) VtuOrder::query()
                        ->where('user_id', $spender->id)
                        ->where('status', 'successful')
                        ->where('created_at', '>=', now()->subDays(30))
                        ->sum('amount_minor');

                    return ['id' => (int) $spender->id, 'name' => (string) $spender->name, 'spendMinor' => $spendMinor];
                })->values()
            : collect();

        $selectedDriver = (string) $settings->get('vtu.driver', config('services.vtu.driver', 'configured'));
        $multiProviderEnabled = (bool) config('services.vtu.multi_provider.enabled', false);
        $providerIntegrations = [
            [
                'name' => 'VTpass',
                'selected' => $selectedDriver === 'vtpass',
                'credentialsConfigured' => trim((string) config('services.vtu.vtpass.api_key', '')) !== ''
                    && trim((string) config('services.vtu.vtpass.secret_key', '')) !== '',
                'status' => $selectedDriver === 'vtpass' ? 'Technical review hold' : 'Not selected',
                'purchasesAllowed' => false,
            ],
        ];
        foreach (['sogo' => 'Sogo', 'gsubz' => 'GSUBZ', 'dataway' => 'Dataway'] as $key => $label) {
            $credentialsConfigured = trim((string) config('services.vtu.'.$key.'.api_key', '')) !== '';
            $providerIntegrations[] = [
                'name' => $label,
                'selected' => $multiProviderEnabled && in_array($key, (array) config('services.vtu.multi_provider.priority', []), true),
                'credentialsConfigured' => $credentialsConfigured,
                'status' => ! $multiProviderEnabled
                    ? 'Routing disabled'
                    : ($credentialsConfigured ? 'Configured — release checks required' : 'Credentials missing'),
                'purchasesAllowed' => false,
            ];
        }

        foreach ($providerIntegrations as &$providerIntegration) {
            $providerIntegration['performance'] = $providerPerformance[$providerIntegration['name'] === 'VTpass' ? 'vtpass' : strtolower($providerIntegration['name'])] ?? [
                'total' => 0, 'successful' => 0, 'failed' => 0, 'successRate' => null, 'averageResponseSeconds' => null,
            ];
        }
        unset($providerIntegration);
        return Inertia::render('Admin/Dashboard', [
            'providerIntegrations' => $providerIntegrations,
            'capabilities' => [
                'manageStaff' => $canManageStaff,
                'approveRefunds' => $canApproveRefunds,
            ],
            'metrics' => [
                'users' => $canManageStaff ? User::query()->count() : null,
                'wallets' => Wallet::query()->count(),
                'pendingFunding' => PaymentIntent::query()->whereIn('status', ['pending', 'processing'])->count(),
                'pendingManualDeposits' => $canManageStaff ? ManualDeposit::query()->where('status', 'pending')->count() : null,
                'refundsNeedingAction' => $canApproveRefunds
                    ? PaymentRefund::query()->whereIn('status', ['requested', 'blocked_insufficient_wallet_funds', 'review_required'])->count()
                    : null,
                'unresolvedVtuOrders' => VtuOrder::query()->whereIn('status', ['processing', 'awaiting_reconciliation'])->count(),
            ],
            'prototypeMetrics' => [
                // These values are deliberately null until backed by verified reporting queries.
                'totalRevenueMinor' => null,
                'activeUsers' => null,
                'newUsersToday' => $newUsersToday,
                'highValueUsers' => null, // No agreed spend threshold; the ranked list below is based on actual VTU purchases.
                'apiSuccessRate' => $apiSuccessRate,
                'pendingTransactions' => WalletTransaction::query()
                    ->whereIn('status', ['pending', 'processing', 'awaiting_reconciliation'])
                    ->count(),
            ],
            'newUsersLastSevenDays' => $newUsersLastSevenDays,
            'recentUsers' => $recentUsers,
            'topVtuSpenders' => $topVtuSpenders,
        ]);
    }

    private function renderCustomerDashboard(Request $request): InertiaResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', 'NGN')
            ->first();

        $transactionSearch = trim((string) $request->query('transaction_search', ''));
        $transactionSearch = mb_substr($transactionSearch, 0, 100);
        $transactionStatus = (string) $request->query('transaction_status', 'all');
        if (! in_array($transactionStatus, ['all', 'pending', 'successful', 'failed'], true)) {
            $transactionStatus = 'all';
        }
        $transactionPage = max(1, min(100000, (int) $request->query('transactions_page', 1)));

        $transactionQuery = $wallet
            ? WalletTransaction::query()->where('wallet_id', $wallet->id)
            : WalletTransaction::query()->whereRaw('1 = 0');

        if ($transactionSearch !== '') {
            $transactionQuery->where(function ($query) use ($transactionSearch): void {
                $term = '%' . addcslashes($transactionSearch, '\\%_') . '%';
                $query->where('description', 'like', $term)
                    ->orWhere('type', 'like', $term)
                    ->orWhere('status', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('public_id', 'like', $term);
            });
        }

        match ($transactionStatus) {
            'pending' => $transactionQuery->whereIn('status', ['pending', 'processing', 'awaiting_reconciliation']),
            'successful' => $transactionQuery->whereIn('status', ['succeeded', 'successful', 'completed', 'paid']),
            'failed' => $transactionQuery->whereIn('status', ['failed', 'rejected', 'cancelled']),
            default => null,
        };

        $transactionPaginator = $transactionQuery
            ->latest('id')
            ->paginate(10, ['public_id', 'type', 'status', 'currency', 'amount_minor', 'description', 'reference', 'created_at'], 'transactions_page', $transactionPage);

        $orderSearch = trim((string) $request->query('order_search', ''));
        $orderSearch = mb_substr($orderSearch, 0, 100);
        $orderStatus = (string) $request->query('order_status', 'all');
        if (! in_array($orderStatus, ['all', 'pending', 'processing', 'successful', 'failed'], true)) {
            $orderStatus = 'all';
        }
        $orderPage = max(1, min(100000, (int) $request->query('orders_page', 1)));

        $orderQuery = VtuOrder::query()->where('user_id', $user->id);
        if ($orderSearch !== '') {
            $orderQuery->where(function ($query) use ($orderSearch): void {
                $term = '%' . addcslashes($orderSearch, '\\%_') . '%';
                $query->where('network', 'like', $term)
                    ->orWhere('service_type', 'like', $term)
                    ->orWhere('recipient', 'like', $term)
                    ->orWhere('status', 'like', $term)
                    ->orWhere('public_id', 'like', $term)
                    ->orWhere('provider_reference', 'like', $term);
            });
        }

        match ($orderStatus) {
            'pending' => $orderQuery->whereIn('status', ['pending', 'processing', 'awaiting_reconciliation']),
            'processing' => $orderQuery->whereIn('status', ['processing', 'awaiting_reconciliation']),
            'successful' => $orderQuery->where('status', 'successful'),
            'failed' => $orderQuery->whereIn('status', ['failed', 'cancelled']),
            default => null,
        };

        $orderPaginator = $orderQuery
            ->latest('id')
            ->paginate(10, ['public_id', 'service_type', 'network', 'recipient', 'amount_minor', 'currency', 'status', 'created_at'], 'orders_page', $orderPage);

        return Inertia::render('Dashboard', [
            'customer' => [
                'name' => (string) $user->name,
                'type' => $user->user_type ?? 'customer',
                'tier' => $user->tier ?? null,
                'level' => $user->user_level ?? $user->level ?? 'level_1',
                'avatarUrl' => $user->avatar_url ?? $user->profile_photo_url ?? null,
                'kycStatus' => $user->kyc_status ?? null,
            ],
            'isAdminPreview' => $this->hasAdminAccess($user->id),
            'wallet' => $wallet ? [
                'balance_minor' => (int) $wallet->balance_minor,
                'available_balance_minor' => (int) $wallet->available_balance_minor,
                'currency' => $wallet->currency,
                'status' => $wallet->status,
            ] : null,
            'transactions' => $transactionPaginator->getCollection()->map(fn (WalletTransaction $transaction): array => [
                'id' => $transaction->public_id,
                'type' => $transaction->type,
                'status' => $transaction->status,
                'currency' => $transaction->currency,
                'amount_minor' => (int) $transaction->amount_minor,
                'description' => $transaction->description,
                'reference' => $transaction->reference,
                'created_at' => $transaction->created_at?->toISOString(),
            ])->values(),
            'transactionPagination' => [
                'currentPage' => $transactionPaginator->currentPage(),
                'lastPage' => $transactionPaginator->lastPage(),
                'total' => $transactionPaginator->total(),
            ],
            'transactionFilters' => ['search' => $transactionSearch, 'status' => $transactionStatus],
            'orders' => $orderPaginator->getCollection()->map(fn (VtuOrder $order): array => [
                'id' => $order->public_id,
                'service_type' => $order->service_type,
                'network' => $order->network,
                'recipient' => $order->recipient,
                'amount_minor' => (int) $order->amount_minor,
                'currency' => $order->currency,
                'status' => $order->status,
                'created_at' => $order->created_at?->toISOString(),
            ])->values(),
            'orderPagination' => [
                'currentPage' => $orderPaginator->currentPage(),
                'lastPage' => $orderPaginator->lastPage(),
                'total' => $orderPaginator->total(),
            ],
            'orderFilters' => ['search' => $orderSearch, 'status' => $orderStatus],
            'fundingHistory' => $wallet
                ? PaymentIntent::query()->where('wallet_id', $wallet->id)->latest('id')->limit(5)
                    ->get(['public_id', 'reference', 'amount_minor', 'currency', 'status', 'created_at'])
                    ->map(fn (PaymentIntent $intent): array => [
                        'id' => $intent->public_id,
                        'reference' => $intent->reference,
                        'amount_minor' => (int) $intent->amount_minor,
                        'currency' => $intent->currency,
                        'status' => $intent->status,
                        'created_at' => $intent->created_at?->toISOString(),
                    ])->values()
                : [],
        ]);
    }

    private function hasAdminAccess(int $userId): bool
    {
        return UserPermission::query()
            ->where('user_id', $userId)
            ->whereIn('permission', ['staff.permissions.manage', 'refunds.approve'])
            ->exists();
    }
}
