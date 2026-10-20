<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentIntentService
{
    public function create(Wallet $wallet, int $amountMinor, string $provider = 'paystack', string $gatewayMode = 'test', ?string $idempotencyKey = null): PaymentIntent
    {
        if ($amountMinor < 100 || $amountMinor > 500_000_000) {
            throw new InvalidArgumentException('Funding amount must be between 100 and 500000000 minor units.');
        }

        if (! in_array($gatewayMode, ['test', 'live'], true)) {
            throw new InvalidArgumentException('Funding gateway mode must be test or live.');
        }

        if ($wallet->status !== 'active' || $wallet->currency !== 'NGN') {
            throw new InvalidArgumentException('Only active NGN wallets can create funding intents.');
        }

        $ttl = max(5, min(120, (int) config('services.semizzy_payments.intent_ttl_minutes', 30)));

        return DB::transaction(function () use ($wallet, $amountMinor, $provider, $gatewayMode, $idempotencyKey, $ttl): PaymentIntent {
            // Serialize funding-intent creation per wallet so concurrent retries with the same
            // client key cannot create multiple checkout references.
            Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $keyHash = $idempotencyKey !== null && trim($idempotencyKey) !== ''
                ? hash('sha256', trim($idempotencyKey))
                : null;
            if ($keyHash !== null) {
                $existing = PaymentIntent::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('provider', $provider)
                    ->where('metadata->client_idempotency_key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if ($existing->amount_minor !== $amountMinor) {
                        throw new InvalidArgumentException('This funding retry key was already used with a different amount.');
                    }
                    if (($existing->metadata['gateway_mode'] ?? null) !== $gatewayMode) {
                        throw new InvalidArgumentException('This funding retry key is already bound to a different gateway mode.');
                    }
                    if ($existing->status !== 'pending' || ($existing->expires_at && $existing->expires_at->isPast())) {
                        throw new InvalidArgumentException('This funding retry key belongs to an expired or completed attempt. Start a new attempt before retrying.');
                    }
                    return $existing;
                }
            }
            return PaymentIntent::query()->create([
                'public_id' => (string) Str::uuid(),
                'wallet_id' => $wallet->id,
                'provider' => $provider,
                'reference' => 'SZF-'.Str::upper((string) Str::ulid()),
                'currency' => $wallet->currency,
                'amount_minor' => $amountMinor,
                'status' => 'pending',
                'metadata' => array_filter([
                    'gateway_mode' => $gatewayMode,
                    'client_idempotency_key_hash' => $keyHash,
                ], static fn ($value) => $value !== null),
                'expires_at' => now()->addMinutes($ttl),
            ]);
        });
    }
}