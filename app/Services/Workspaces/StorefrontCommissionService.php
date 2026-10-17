<?php

namespace App\Services\Workspaces;

use App\Models\BusinessWorkspaceCommission;
use App\Models\BusinessWorkspaceStorefront;
use App\Models\User;
use App\Models\VtuOrder;
use App\Models\Wallet;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StorefrontCommissionService
{
    public function __construct(
        private readonly WalletAccountService $walletAccounts,
        private readonly WalletLedgerService $ledger,
    ) {
    }

    /**
     * Accrue only from a persisted successful order. The unique order constraint
     * and the caller's order-row lock prevent duplicate commission records.
     */
    public function accrueSuccessfulOrder(VtuOrder $order): ?BusinessWorkspaceCommission
    {
        if ($order->status !== 'successful' || $order->business_workspace_storefront_id === null) {
            return null;
        }

        $storefront = BusinessWorkspaceStorefront::query()
            ->whereKey($order->business_workspace_storefront_id)
            ->lockForUpdate()
            ->first();

        if ($storefront === null || $storefront->commission_rate_basis_points < 1) {
            return null;
        }

        $workspace = $storefront->workspace()->first();
        if ($workspace === null) {
            return null;
        }

        $commissionMinor = intdiv(
            ((int) $order->amount_minor) * ((int) $storefront->commission_rate_basis_points),
            10000,
        );

        if ($commissionMinor < 1) {
            return null;
        }

        return BusinessWorkspaceCommission::query()->firstOrCreate(
            ['vtu_order_id' => $order->getKey()],
            [
                'business_workspace_storefront_id' => $storefront->getKey(),
                'beneficiary_user_id' => $workspace->owner_user_id,
                'gross_amount_minor' => (int) $order->amount_minor,
                'rate_basis_points' => (int) $storefront->commission_rate_basis_points,
                'commission_amount_minor' => $commissionMinor,
                'status' => 'accrued',
            ],
        );
    }

    /**
     * Settle an accrued commission as an idempotent credit to the workspace
     * owner's Semizzy wallet. Callers must be protected by admin permission.
     */
    public function settle(int $commissionId, User $actor): BusinessWorkspaceCommission
    {
        return DB::transaction(function () use ($commissionId, $actor): BusinessWorkspaceCommission {
            $commission = BusinessWorkspaceCommission::query()
                ->whereKey($commissionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($commission->status === 'settled') {
                return $commission;
            }

            if ($commission->status !== 'accrued'
                || (int) $commission->commission_amount_minor < 1
                || $commission->order()->value('status') !== 'successful') {
                throw new InvalidArgumentException('Only a positive commission from a verified successful order can be settled.');
            }

            $beneficiary = User::query()->whereKey($commission->beneficiary_user_id)->firstOrFail();
            $wallet = Wallet::query()
                ->where('user_id', $beneficiary->getKey())
                ->where('currency', 'NGN')
                ->first();

            if ($wallet === null) {
                $wallet = $this->walletAccounts->openForUser($beneficiary, 'NGN');
            }

            $transaction = $this->ledger->post(
                walletId: (int) $wallet->getKey(),
                type: 'credit',
                amountMinor: (int) $commission->commission_amount_minor,
                idempotencyKey: 'reseller-commission:settlement:'.$commission->getKey(),
                reference: $commission->order()->value('public_id'),
                description: 'Reseller commission settlement',
                actorUserId: (int) $actor->getKey(),
                metadata: [
                    'business_workspace_commission_id' => $commission->getKey(),
                    'vtu_order_id' => $commission->order()->value('public_id'),
                    'rate_basis_points' => (int) $commission->rate_basis_points,
                ],
                correlationId: 'reseller-commission-'.$commission->getKey(),
            );

            $commission->forceFill([
                'status' => 'settled',
                'settled_wallet_transaction_id' => $transaction->getKey(),
                'settled_at' => now(),
            ])->save();

            return $commission->fresh();
        }, attempts: 3);
    }
}
