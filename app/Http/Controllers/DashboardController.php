<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\VtuOrder;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', 'NGN')
            ->first();

        $transactions = $wallet
            ? WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->latest('id')
                ->limit(5)
                ->get(['public_id', 'type', 'status', 'currency', 'amount_minor', 'description', 'reference', 'created_at'])
            : collect();

        $orders = VtuOrder::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(5)
            ->get(['public_id', 'service_type', 'network', 'recipient', 'amount_minor', 'currency', 'status', 'created_at']);

        return Inertia::render('Dashboard', [
            'wallet' => $wallet ? [
                'balance_minor' => (int) $wallet->balance_minor,
                'available_balance_minor' => (int) $wallet->available_balance_minor,
                'currency' => $wallet->currency,
                'status' => $wallet->status,
            ] : null,
            'transactions' => $transactions->map(fn (WalletTransaction $transaction): array => [
                'id' => $transaction->public_id,
                'type' => $transaction->type,
                'status' => $transaction->status,
                'currency' => $transaction->currency,
                'amount_minor' => (int) $transaction->amount_minor,
                'description' => $transaction->description,
                'reference' => $transaction->reference,
                'created_at' => $transaction->created_at?->toISOString(),
            ])->values(),
            'orders' => $orders->map(fn (VtuOrder $order): array => [
                'id' => $order->public_id,
                'service_type' => $order->service_type,
                'network' => $order->network,
                'recipient' => $order->recipient,
                'amount_minor' => (int) $order->amount_minor,
                'currency' => $order->currency,
                'status' => $order->status,
                'created_at' => $order->created_at?->toISOString(),
            ])->values(),
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

    public function admin(Request $request): InertiaResponse
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

        return Inertia::render('Admin/Dashboard', [
            'capabilities' => [
                'manageStaff' => $canManageStaff,
                'approveRefunds' => $canApproveRefunds,
            ],
            'metrics' => [
                'users' => $canManageStaff ? User::query()->count() : null,
                'wallets' => Wallet::query()->count(),
                'pendingFunding' => PaymentIntent::query()->whereIn('status', ['pending', 'processing'])->count(),
                'refundsNeedingAction' => $canApproveRefunds
                    ? PaymentRefund::query()->whereIn('status', ['requested', 'blocked_insufficient_wallet_funds', 'review_required'])->count()
                    : null,
                'unresolvedVtuOrders' => VtuOrder::query()->whereIn('status', ['processing', 'awaiting_reconciliation'])->count(),
            ],
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
