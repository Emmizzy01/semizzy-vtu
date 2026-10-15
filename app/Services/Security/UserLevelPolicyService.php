<?php

namespace App\Services\Security;

use App\Models\User;
use App\Models\UserLevelPolicy;
use App\Models\VtuProduct;
use App\Models\WalletTransaction;

class UserLevelPolicyService
{
    public function rejectionReason(User $user, VtuProduct $product, int $walletId): ?string
    {
        $currentUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $level = (string) ($currentUser->user_level ?: 'level_1');
        $policy = UserLevelPolicy::query()->where('level', $level)->lockForUpdate()->first();
        if (! $policy || ! $policy->is_active) return 'Transactions are disabled for this account level. Contact support.';
        if ($policy->requires_verified_kyc && $currentUser->kyc_status !== 'verified') return 'Complete KYC verification before using this transaction service.';
        $amount = (int) $product->price_minor;
        if ($policy->max_single_transaction_minor !== null && $amount > (int) $policy->max_single_transaction_minor) {
            return 'This purchase exceeds the single-transaction limit for your account level.';
        }
        if ($policy->daily_transaction_limit_minor !== null) {
            $spent = (int) WalletTransaction::query()->where('wallet_id', $walletId)->where('type', 'debit')
                ->where('status', 'posted')->where('created_at', '>=', now()->startOfDay())->sum('amount_minor');
            if ($spent + $amount > (int) $policy->daily_transaction_limit_minor) {
                return 'This purchase would exceed the daily transaction limit for your account level.';
            }
        }
        return null;
    }
}
