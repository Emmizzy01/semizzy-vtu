<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Payments\PaymentIntentService;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentProviderExceptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_provider_event_secret']);
    }

    public function test_signed_processed_refund_is_recorded_without_automatic_wallet_mutation(): void
    {
        $user = User::query()->create([
            'name' => 'Refund Event Customer',
            'email' => 'refund-event@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 25000, 'paystack');

        $payload = [
            'event' => 'refund.processed',
            'data' => [
                'status' => 'processed',
                'transaction_reference' => $intent->reference,
                'refund_reference' => 'refund_123',
                'amount' => '25000',
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, 'sk_test_provider_event_secret');

        $response = $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $raw);

        $response->assertOk()->assertJsonPath('outcome', 'recorded_for_review');
        $this->assertDatabaseHas('payment_reconciliation_cases', [
            'reference' => $intent->reference,
            'case_type' => 'refund_processed',
            'status' => 'open',
            'observed_amount_minor' => 25000,
        ]);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_signed_chargeback_event_is_recorded_without_automatic_wallet_debit(): void
    {
        $user = User::query()->create([
            'name' => 'Dispute Event Customer',
            'email' => 'dispute-event@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 15000, 'paystack');

        $payload = [
            'event' => 'charge.dispute.create',
            'data' => [
                'status' => 'pending',
                'transaction_reference' => $intent->reference,
                'id' => 9911,
                'amount' => 15000,
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, 'sk_test_provider_event_secret');

        $response = $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $raw);

        $response->assertOk()->assertJsonPath('outcome', 'recorded_for_review');
        $this->assertDatabaseHas('payment_reconciliation_cases', [
            'reference' => $intent->reference,
            'case_type' => 'chargeback_open',
            'status' => 'open',
        ]);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_invalid_signature_cannot_create_refund_or_dispute_case(): void
    {
        $payload = [
            'event' => 'refund.processed',
            'data' => [
                'transaction_reference' => 'untrusted-reference',
                'amount' => '50000',
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $response = $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => str_repeat('0', 128),
        ], $raw);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('payment_reconciliation_cases', 0);
    }
}
