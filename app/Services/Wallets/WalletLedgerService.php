<?php

namespace App\Services\Wallets;

use App\Exceptions\Wallet\IdempotencyConflict;
use App\Exceptions\Wallet\InsufficientWalletFunds;
use App\Models\Wallet;
use App\Models\User;
use App\Models\UserLevelPolicy;
use App\Models\WalletAuditEvent;
use App\Models\WalletLedgerEntry;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use DomainException;
use InvalidArgumentException;

class WalletLedgerService
{
    /**
     * Post a credit or debit in minor currency units (kobo for NGN).
     *
     * Call only from trusted server-side business logic. Do not expose a generic
     * client-controlled credit endpoint; credits require a verified funding event.
     *
     * @param array<string, mixed> $metadata
     */
    public function post(
        int $walletId,
        string $type,
        int $amountMinor,
        string $idempotencyKey,
        ?string $reference = null,
        ?string $description = null,
        ?int $actorUserId = null,
        array $metadata = [],
        ?string $correlationId = null,
        bool $isFinancialRecovery = false,
    ): WalletTransaction {
        $type = strtolower($type);

        if (! in_array($type, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('Wallet transaction type must be credit or debit.');
        }

        if ($amountMinor < 1) {
            throw new InvalidArgumentException('Wallet amount must be a positive integer in minor units.');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('Idempotency key must contain 1 to 128 characters.');
        }

        if ($reference !== null && strlen($reference) > 100) {
            throw new InvalidArgumentException('Reference cannot exceed 100 characters.');
        }

        $requestHash = hash('sha256', json_encode([
            'wallet_id' => $walletId,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'reference' => $reference,
            'description' => $description,
            'metadata' => $metadata,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            return DB::transaction(function () use (
                $walletId,
                $type,
                $amountMinor,
                $idempotencyKey,
                $requestHash,
                $reference,
                $description,
                $actorUserId,
                $metadata,
                $correlationId,
                $isFinancialRecovery,
            ): WalletTransaction {
                /** @var Wallet $wallet */
                $wallet = Wallet::query()->whereKey($walletId)->lockForUpdate()->firstOrFail();

                $existing = WalletTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        throw new IdempotencyConflict(
                            'This idempotency key was already used for a different wallet operation.'
                        );
                    }

                    return $existing;
                }

                if ($wallet->status !== 'active') {
                    throw new InvalidArgumentException('Wallet is not active.');
                }

                $balanceBefore = (int) $wallet->balance_minor;
                $availableBefore = (int) $wallet->available_balance_minor;

                // The wallet row lock serializes credits, so this check and the balance
                // update are atomic even when two deposits arrive at the same time.
                if ($type === 'credit' && ! $isFinancialRecovery) {
                    $owner = User::query()->whereKey($wallet->user_id)->first();
                    $level = (string) ($owner?->user_level ?: 'level_1');
                    $policy = UserLevelPolicy::query()->where('level', $level)->lockForUpdate()->first();

                    if ($policy?->is_active && $policy->wallet_balance_limit_minor !== null
                        && $balanceBefore + $amountMinor > (int) $policy->wallet_balance_limit_minor) {
                        throw new DomainException('Wallet balance limit for this account level would be exceeded; credit requires reconciliation review.');
                    }
                }

                if ($type === 'debit' && $availableBefore < $amountMinor) {
                    throw new InsufficientWalletFunds('Insufficient available wallet funds.');
                }

                if (
                    $type === 'credit'
                    && (
                        $balanceBefore > PHP_INT_MAX - $amountMinor
                        || $availableBefore > PHP_INT_MAX - $amountMinor
                    )
                ) {
                    throw new InvalidArgumentException('Wallet balance limit would be exceeded.');
                }

                $balanceAfter = $type === 'credit'
                    ? $balanceBefore + $amountMinor
                    : $balanceBefore - $amountMinor;

                $availableAfter = $type === 'credit'
                    ? $availableBefore + $amountMinor
                    : $availableBefore - $amountMinor;

                if ($availableAfter < 0) {
                    throw new InsufficientWalletFunds('Insufficient available wallet funds.');
                }

                $transaction = WalletTransaction::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'wallet_id' => $wallet->id,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'type' => $type,
                    'status' => 'pending',
                    'currency' => $wallet->currency,
                    'amount_minor' => $amountMinor,
                    'balance_before_minor' => $balanceBefore,
                    'balance_after_minor' => $balanceAfter,
                    'reference' => $reference,
                    'description' => $description,
                    'metadata' => $metadata,
                ]);

                $wallet->forceFill([
                    'balance_minor' => $balanceAfter,
                    'available_balance_minor' => $availableAfter,
                    'version' => ((int) $wallet->version) + 1,
                ])->save();

                WalletLedgerEntry::query()->create([
                    'wallet_id' => $wallet->id,
                    'wallet_transaction_id' => $transaction->id,
                    'entry_type' => $type,
                    'currency' => $wallet->currency,
                    'amount_minor' => $amountMinor,
                    'balance_after_minor' => $balanceAfter,
                    'memo' => $description,
                    'created_at' => now(),
                ]);

                $transaction->forceFill([
                    'status' => 'posted',
                    'posted_at' => now(),
                ])->save();

                WalletAuditEvent::query()->create([
                    'wallet_id' => $wallet->id,
                    'wallet_transaction_id' => $transaction->id,
                    'actor_user_id' => $actorUserId,
                    'event_type' => 'wallet.transaction.posted',
                    'outcome' => 'success',
                    'correlation_id' => $correlationId,
                    'context' => [
                        'type' => $type,
                        'amount_minor' => $amountMinor,
                        'currency' => $wallet->currency,
                        'balance_before_minor' => $balanceBefore,
                        'balance_after_minor' => $balanceAfter,
                        'reference' => $reference,
                    ],
                    'created_at' => now(),
                ]);

                return $transaction;
            }, attempts: 3);
        } catch (InsufficientWalletFunds $exception) {
            // Record rejected attempts after the balance transaction has rolled back.
            WalletAuditEvent::query()->create([
                'wallet_id' => $walletId,
                'actor_user_id' => $actorUserId,
                'event_type' => 'wallet.transaction.rejected',
                'outcome' => 'rejected',
                'correlation_id' => $correlationId,
                'context' => [
                    'type' => $type,
                    'amount_minor' => $amountMinor,
                    'reason' => 'insufficient_funds',
                ],
                'created_at' => now(),
            ]);

            throw $exception;
        }
    }
}
