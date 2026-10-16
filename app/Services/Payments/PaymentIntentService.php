<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentIntentService
{
    public function create(Wallet $wallet, int $amountMinor, string $provider = 'paystack'): PaymentIntent
    {
        if ($amountMinor < 100 || $amountMinor > 500_000_000) {
            throw new InvalidArgumentException('Funding amount must be between 100 and 500000000 minor units.');
        }

        if ($wallet->status !== 'active' || $wallet->currency !== 'NGN') {
            throw new InvalidArgumentException('Only active NGN wallets can create funding intents.');
        }

        $ttl = max(5, min(120, (int) config('services.semizzy_payments.intent_ttl_minutes', 30)));

        return DB::transaction(function () use ($wallet, $amountMinor, $provider, $ttl): PaymentIntent {
            return PaymentIntent::query()->create([
                'public_id' => (string) Str::uuid(),
                'wallet_id' => $wallet->id,
                'provider' => $provider,
                'reference' => 'SZF-'.Str::upper((string) Str::ulid()),
                'currency' => $wallet->currency,
                'amount_minor' => $amountMinor,
                'status' => 'pending',
                'expires_at' => now()->addMinutes($ttl),
            ]);
        });
    }
}