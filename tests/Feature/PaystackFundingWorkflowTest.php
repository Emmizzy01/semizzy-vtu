<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\User;
use App\Services\Payments\PaymentIntentService;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaystackFundingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => 'sk_test_semizzy_test_secret',
            'services.paystack.callback_url' => 'https://semizzy.test/wallet/funding/return',
        ]);
    }

    public function test_authenticated_customer_can_initialize_paystack_checkout(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => function ($request) {
                return Http::response([
                    'status' => true,
                    'message' => 'Authorization URL created',
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/test-session',
                        'access_code' => 'access_test_123',
                        'reference' => $request['reference'],
                    ],
                ], 200);
            },
        ]);

        $user = User::query()->create([
            'name' => 'Paystack Customer',
            'email' => 'paystack@example.test',
            'password' => 'not-a-real-password',
        ]);

        $response = $this->actingAs($user)->postJson('/wallet/funding-intents', [
            'amount_minor' => 25000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://checkout.paystack.com/test-session')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('payment_intents', [
            'provider' => 'paystack',
            'amount_minor' => 25000,
            'checkout_url' => 'https://checkout.paystack.com/test-session',
            'status' => 'pending',
        ]);

        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/transaction/initialize')
                && $request->hasHeader('Authorization', 'Bearer sk_test_semizzy_test_secret')
                && $request['amount'] === 25000
                && $request['currency'] === 'NGN'
                && $request['email'] === 'paystack@example.test'
                && isset($request['reference']);
        });
    }

    public function test_signed_paystack_webhook_verifies_transaction_before_single_credit(): void
    {
        $user = User::query()->create([
            'name' => 'Paystack Webhook Customer',
            'email' => 'paystack-hook@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack');

        Http::fake([
            'api.paystack.co/transaction/verify/*' => function ($request) use ($intent) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'id' => 987654,
                        'status' => 'success',
                        'reference' => $intent->reference,
                        'amount' => 12500,
                        'currency' => 'NGN',
                    ],
                ], 200);
            },
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 987654,
                'status' => 'success',
                'reference' => $intent->reference,
                'amount' => 12500,
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, 'sk_test_semizzy_test_secret');

        $send = function () use ($raw, $signature) {
            return $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
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

        Http::assertSent(function ($request) use ($intent): bool {
            return str_contains($request->url(), '/transaction/verify/')
                && str_ends_with($request->url(), rawurlencode($intent->reference))
                && $request->hasHeader('Authorization', 'Bearer sk_test_semizzy_test_secret');
        });
    }

    public function test_invalid_paystack_signature_does_not_call_verify_or_credit(): void
    {
        Http::fake();

        $response = $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => str_repeat('0', 128),
        ], '{"event":"charge.success","data":{"id":1,"reference":"not-real","amount":100,"currency":"NGN"}}');

        $response->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_paystack_amount_mismatch_never_credits_wallet(): void
    {
        $user = User::query()->create([
            'name' => 'Mismatch Customer',
            'email' => 'mismatch@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack');

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 12345,
                    'status' => 'success',
                    'reference' => $intent->reference,
                    'amount' => 50000,
                    'currency' => 'NGN',
                ],
            ], 200),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 12345,
                'status' => 'success',
                'reference' => $intent->reference,
                'amount' => 50000,
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, 'sk_test_semizzy_test_secret');

        $response = $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $raw);

        $response->assertStatus(409);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
    public function test_checkout_uses_database_saved_paystack_secret_and_callback_url(): void
    {
        IntegrationSetting::query()->create([
            'key' => 'paystack.secret_key',
            'value' => 'sk_test_database_saved_secret',
        ]);
        IntegrationSetting::query()->create([
            'key' => 'paystack.callback_url',
            'value' => 'https://semizzy.example.test/wallet/funding/return',
        ]);

        Http::fake([
            'api.paystack.co/transaction/initialize' => function ($request) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/database-settings-session',
                        'access_code' => 'access_database_settings',
                        'reference' => $request['reference'],
                    ],
                ], 200);
            },
        ]);

        $user = User::query()->create([
            'name' => 'Database Settings Customer',
            'email' => 'database-settings@example.test',
            'password' => 'not-a-real-password',
        ]);

        $response = $this->actingAs($user)->postJson('/wallet/funding-intents', [
            'amount_minor' => 25000,
        ]);

        $response->assertCreated();

        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/transaction/initialize')
                && $request->hasHeader('Authorization', 'Bearer sk_test_database_saved_secret')
                && $request['callback_url'] === 'https://semizzy.example.test/wallet/funding/return';
        });
    }

    public function test_webhook_uses_database_saved_secret_for_signature_and_verification(): void
    {
        IntegrationSetting::query()->create([
            'key' => 'paystack.secret_key',
            'value' => 'sk_test_database_webhook_secret',
        ]);

        $user = User::query()->create([
            'name' => 'Database Webhook Customer',
            'email' => 'database-webhook@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack');

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 445566,
                    'status' => 'success',
                    'reference' => $intent->reference,
                    'amount' => 12500,
                    'currency' => 'NGN',
                ],
            ], 200),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 445566,
                'status' => 'success',
                'reference' => $intent->reference,
                'amount' => 12500,
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, 'sk_test_database_webhook_secret');

        $response = $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $raw);

        $response->assertOk()->assertJsonPath('outcome', 'credited');
        $this->assertSame(12500, (int) $wallet->fresh()->balance_minor);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/transaction/verify/')
                && $request->hasHeader('Authorization', 'Bearer sk_test_database_webhook_secret');
        });
    }

    public function test_webhook_transaction_id_mismatch_never_credits_wallet(): void
    {
        $user = User::query()->create([
            'name' => 'Transaction ID Mismatch Customer',
            'email' => 'transaction-id-mismatch@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack');

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 222222,
                    'status' => 'success',
                    'reference' => $intent->reference,
                    'amount' => 12500,
                    'currency' => 'NGN',
                ],
            ], 200),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 111111,
                'status' => 'success',
                'reference' => $intent->reference,
                'amount' => 12500,
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, 'sk_test_semizzy_test_secret');

        $response = $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $raw);

        $response->assertStatus(409);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_verified_non_ngn_paystack_transaction_never_credits_wallet(): void
    {
        $user = User::query()->create([
            'name' => 'Currency Mismatch Customer',
            'email' => 'currency-mismatch@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack');

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 333333,
                    'status' => 'success',
                    'reference' => $intent->reference,
                    'amount' => 12500,
                    'currency' => 'USD',
                ],
            ], 200),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 333333,
                'status' => 'success',
                'reference' => $intent->reference,
                'amount' => 12500,
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, 'sk_test_semizzy_test_secret');

        $response = $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $raw);

        $response->assertStatus(409);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_malformed_paystack_secret_key_never_reaches_provider_api(): void
    {
        config(['services.paystack.secret_key' => 'not-a-paystack-secret']);
        Http::fake();

        $user = User::query()->create([
            'name' => 'Malformed Key Customer',
            'email' => 'malformed-key@example.test',
            'password' => 'not-a-real-password',
        ]);

        $response = $this->actingAs($user)->postJson('/wallet/funding-intents', [
            'amount_minor' => 25000,
        ]);

        $response->assertStatus(502);
        Http::assertNothingSent();
        $this->assertSame(0, (int) $user->wallets()->firstOrFail()->fresh()->balance_minor);
    }

    public function test_browser_return_never_credits_even_when_paystack_reports_success(): void
    {
        $user = User::query()->create([
            'name' => 'Return Customer',
            'email' => 'return@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 15000, 'paystack');

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 778899,
                    'status' => 'success',
                    'reference' => $intent->reference,
                    'amount' => 15000,
                    'currency' => 'NGN',
                ],
            ], 200),
        ]);

        $response = $this->getJson('/wallet/funding/return?reference='.$intent->reference);

        $response->assertOk()->assertJsonPath('status', 'pending');
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
    }

}
