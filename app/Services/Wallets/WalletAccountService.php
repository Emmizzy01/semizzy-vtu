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
                // This is an existence check, not a balance mutation. Do not
                // take a locking read here: callers may already be inside a wallet
                // transaction, and locking reads on this secondary-key lookup
                // triggered MariaDB error 123 under default snapshot isolation.
                // Actual money movement locks the wallet by its primary key in the
                // ledger service; the unique (user_id, currency) index arbitrates
                // concurrent wallet creation.
                $existing = Wallet::query()
                    ->where('user_id', $user->getKey())
                    ->where('currency', $currency)
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
            $sqlState = $exception->errorInfo[0] ?? null;

            // Only inspect for a concurrent insert after a likely uniqueness or
            // integrity conflict. Preserve unrelated database failures unchanged.
            if (! in_array($sqlState, ['23000', '23505'], true)) {
                throw $exception;
            }

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
