<?php

namespace App\Services\Wallets;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletAccountService
{
    public function openForUser(User $user, string $currency = 'NGN'): Wallet
    {
        $currency = strtoupper($currency);

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter ISO-style code.');
        }

        return DB::transaction(function () use ($user, $currency): Wallet {
            // The unique (user_id, currency) constraint is the final concurrency guard.
            $existing = Wallet::query()
                ->where('user_id', $user->getKey())
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return Wallet::query()->create([
                'user_id' => $user->getKey(),
                'currency' => $currency,
                'status' => 'active',
                'balance_minor' => 0,
                'available_balance_minor' => 0,
                'version' => 0,
            ]);
        }, attempts: 3);
    }
}
