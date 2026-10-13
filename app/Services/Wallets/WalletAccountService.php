<?php

namespace App\Services\Wallets;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
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

        try {
            return DB::transaction(function () use ($user, $currency): Wallet {
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
        } catch (QueryException $exception) {
            // Concurrent first-time creation may win the unique index race.
            // Recover only if the intended wallet now exists; otherwise preserve
            // the original database exception for diagnostics.
            $existing = Wallet::query()
                ->where('user_id', $user->getKey())
                ->where('currency', $currency)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }
}
