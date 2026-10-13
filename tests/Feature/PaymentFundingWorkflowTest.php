<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Payments\PaymentIntentService;
use App\Services\Wallets\WalletAccountService;
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

        $first = app(PaymentIntentService::class)->create($wallet, 25000);
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
        $intent = app(PaymentIntentService::class)->create($wallet, 12500);

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
            return $this->withHeaders([
                'X-Semizzy-Signature' => $header,
                'Content-Type' => 'application/json',
            ])->call('POST', '/api/payments/webhooks/semizzy', [], [], [], [], $raw);
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
}