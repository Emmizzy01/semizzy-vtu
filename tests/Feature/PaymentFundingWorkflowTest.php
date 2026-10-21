<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserLevelPolicy;
use App\Services\Payments\PaymentIntentService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentFundingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_intents_have_unique_references_and_expire(): void
    {
        $user = User::query()->create([
            'name' => 'Funding Customer',
            'email' => 'funding@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);

        $first = app(PaymentIntentService::class)->create($wallet, 25000, 'semizzy');
        $second = app(PaymentIntentService::class)->create($wallet, 25000);

        $this->assertNotSame($first->reference, $second->reference);
        $this->assertSame('pending', $first->status);
        $this->assertSame('NGN', $first->currency);
        $this->assertTrue($first->expires_at->isFuture());
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
    }

    public function test_valid_signed_webhook_credits_wallet_once_and_replay_is_idempotent(): void
    {
        config(['services.semizzy_payments.webhook_secrets.semizzy' => 'test-secret-that-is-long-enough-for-tests']);

        $user = User::query()->create([
            'name' => 'Webhook Customer',
            'email' => 'webhook@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'semizzy');

        $payload = [
            'id' => 'evt_test_0001',
            'type' => 'payment.succeeded',
            'data' => [
                'reference' => $intent->reference,
                'transaction_id' => 'provider_tx_test_0001',
                'status' => 'succeeded',
                'amount_minor' => 12500,
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, 'test-secret-that-is-long-enough-for-tests');
        $header = 't='.$timestamp.',v1='.$signature;

        $send = function () use ($header, $raw) {
            return $this->call('POST', '/api/payments/webhooks/semizzy', [], [], [], [
                'HTTP_X_SEMIZZY_SIGNATURE' => $header,
                'CONTENT_TYPE' => 'application/json',
            ], $raw);
        };

        $first = $send();
        $first->assertOk()->assertJsonPath('outcome', 'credited');

        $second = $send();
        $second->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(12500, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_reused_payment_webhook_event_id_with_changed_payload_is_rejected_without_second_credit(): void
    {
        config(['services.semizzy_payments.webhook_secrets.semizzy' => 'test-secret-that-is-long-enough-for-tests']);

        $user = User::query()->create([
            'name' => 'Webhook Conflict Customer',
            'email' => 'webhook-conflict@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'semizzy');

        $send = function (string $transactionId) use ($intent) {
            $payload = [
                'id' => 'evt_conflict_0001',
                'type' => 'payment.succeeded',
                'data' => [
                    'reference' => $intent->reference,
                    'transaction_id' => $transactionId,
                    'status' => 'succeeded',
                    'amount_minor' => 12500,
                    'currency' => 'NGN',
                ],
            ];
            $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$raw, 'test-secret-that-is-long-enough-for-tests');

            return $this->call('POST', '/api/payments/webhooks/semizzy', [], [], [], [
                'HTTP_X_SEMIZZY_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
                'CONTENT_TYPE' => 'application/json',
            ], $raw);
        };

        $send('provider_tx_conflict_original')->assertOk()->assertJsonPath('outcome', 'credited');

        $send('provider_tx_conflict_changed')->assertStatus(409);

        $this->assertSame(12500, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_verified_gateway_payment_over_wallet_limit_is_preserved_for_reconciliation(): void
    {
        config(['services.semizzy_payments.webhook_secrets.semizzy' => 'test-secret-that-is-long-enough-for-tests']);
        $user = User::query()->create(['name' => 'Capped Funding Customer', 'email' => 'capped-funding@example.test', 'password' => 'not-a-real-password']);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post($wallet->id, 'credit', 9500, 'capped-funding-seed');
        UserLevelPolicy::query()->where('level', 'level_1')->update(['wallet_balance_limit_minor' => 10000, 'is_active' => true]);
        $intent = app(PaymentIntentService::class)->create($wallet, 1000, 'semizzy');
        $payload = ['id' => 'evt_limit_review_0001', 'type' => 'payment.succeeded', 'data' => [
            'reference' => $intent->reference, 'transaction_id' => 'provider_tx_limit_review_0001',
            'status' => 'succeeded', 'amount_minor' => 1000, 'currency' => 'NGN',
        ]];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, 'test-secret-that-is-long-enough-for-tests');
        $response = $this->call('POST', '/api/payments/webhooks/semizzy', [], [], [], [
            'HTTP_X_SEMIZZY_SIGNATURE' => 't='.$timestamp.',v1='.$signature, 'CONTENT_TYPE' => 'application/json',
        ], $raw);

        $response->assertOk()->assertJsonPath('outcome', 'wallet_limit_review');
        $this->assertSame(9500, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('review_required', $intent->fresh()->status);
        $this->assertDatabaseHas('payment_webhook_events', ['event_id' => 'evt_limit_review_0001', 'outcome' => 'wallet_limit_review']);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

    public function test_failed_settlement_transaction_rolls_back_event_and_allows_safe_replay(): void
    {
        $user = User::query()->create([
            'name' => 'Settlement Recovery Customer',
            'email' => 'settlement-recovery@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'semizzy');
        $payload = [
            'id' => 'evt_settlement_recovery_0001',
            'type' => 'payment.succeeded',
            'data' => [
                'reference' => $intent->reference,
                'transaction_id' => 'provider_tx_settlement_recovery_0001',
                'status' => 'succeeded',
                'amount_minor' => 12500,
                'currency' => 'NGN',
            ],
        ];

        $failingLedger = \Mockery::mock(WalletLedgerService::class);
        $failingLedger->shouldReceive('post')->once()->andThrow(new \RuntimeException('Simulated ledger worker failure'));
        try {
            (new \App\Services\Payments\PaymentFundingService($failingLedger))
                ->processVerifiedEvent('semizzy', $payload);
            $this->fail('Expected the simulated settlement failure to escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated ledger worker failure', $exception->getMessage());
        }

        // The webhook receipt and intent update are in the same transaction as ledger posting.
        // A crash/failure before commit must leave no partial settlement and permit safe replay.
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);

        $replayed = app(\App\Services\Payments\PaymentFundingService::class)
            ->processVerifiedEvent('semizzy', $payload);

        $this->assertSame('credited', $replayed['outcome']);
        $this->assertFalse($replayed['duplicate']);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertSame(12500, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

    public function test_invalid_signature_never_credits_wallet(): void
    {
        config(['services.semizzy_payments.webhook_secrets.semizzy' => 'test-secret-that-is-long-enough-for-tests']);

        $response = $this->withHeaders([
            'X-Semizzy-Signature' => 't='.now()->timestamp.',v1='.str_repeat('0', 64),
            'Content-Type' => 'application/json',
        ])->call('POST', '/api/payments/webhooks/semizzy', [], [], [], [], '{"id":"evt_bad","type":"payment.succeeded","data":{}}');

        $response->assertUnauthorized();
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_verified_gateway_funding_respects_wallet_caps_for_all_four_tiers_even_when_disabled(): void
    {
        config(['services.semizzy_payments.webhook_secrets.semizzy' => 'test-secret-that-is-long-enough-for-tests']);

        foreach (['level_1', 'level_2', 'level_3', 'level_4'] as $index => $level) {
            $user = User::query()->create([
                'name' => 'Capped Tier Funding '.$index,
                'email' => 'capped-tier-funding-'.$index.'@example.test',
                'password' => 'not-a-real-password',
            ]);
            $user->forceFill(['user_level' => $level])->save();
            $wallet = app(WalletAccountService::class)->openForUser($user);
            app(WalletLedgerService::class)->post($wallet->id, 'credit', 9500, 'tier-funding-seed-'.$level);
            UserLevelPolicy::query()->where('level', $level)->update([
                'wallet_balance_limit_minor' => 10000,
                'is_active' => false,
            ]);
            $intent = app(PaymentIntentService::class)->create($wallet, 1000, 'semizzy');
            $eventId = 'evt_tier_limit_'.$level;
            $payload = ['id' => $eventId, 'type' => 'payment.succeeded', 'data' => [
                'reference' => $intent->reference,
                'transaction_id' => 'provider_tx_tier_limit_'.$level,
                'status' => 'succeeded',
                'amount_minor' => 1000,
                'currency' => 'NGN',
            ]];
            $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$raw, 'test-secret-that-is-long-enough-for-tests');
            $response = $this->call('POST', '/api/payments/webhooks/semizzy', [], [], [], [
                'HTTP_X_SEMIZZY_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
                'CONTENT_TYPE' => 'application/json',
            ], $raw);

            $response->assertOk()->assertJsonPath('outcome', 'wallet_limit_review');
            $this->assertSame(9500, (int) $wallet->fresh()->balance_minor, 'Funding bypassed wallet cap for '.$level);
            $this->assertSame('review_required', $intent->fresh()->status, 'Funding was not held for review for '.$level);
            $this->assertDatabaseHas('payment_webhook_events', [
                'event_id' => $eventId,
                'outcome' => 'wallet_limit_review',
            ]);
        }

        $this->assertDatabaseCount('wallet_ledger_entries', 4);
    }

}
