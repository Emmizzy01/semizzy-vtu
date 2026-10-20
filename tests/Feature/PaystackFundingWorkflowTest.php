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
        // These mocked settlement tests explicitly exercise live-mode crediting.
        config(['services.semizzy_payments.live_funding_enabled' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => 'sk_test_semizzy_test_secret',
            'services.paystack.callback_url' => 'https://semizzy.test/wallet/funding/return',
        ]);
    }

    public function test_live_mode_rejects_test_credentials_before_any_provider_request(): void
    {
        config(['app.env' => 'production']);
        IntegrationSetting::query()->create(['key' => 'paystack.mode', 'value' => 'live']);
        Http::fake();

        try {
            app(\App\Services\Payments\PaystackClient::class)->verifyTransaction('SZF-TEST-REFERENCE');
            $this->fail('Live mode must reject Paystack test credentials.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('selected Paystack mode does not match', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_verified_test_mode_success_is_recorded_without_crediting_real_wallet(): void
    {
        $user = User::query()->create([
            'name' => 'Test Mode Customer',
            'email' => 'test-mode@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack', 'test');
        $intent->forceFill(['metadata' => ['gateway_mode' => 'test']])->save();

        $result = app(\App\Services\Payments\PaymentFundingService::class)->processVerifiedEvent('paystack', [
            'id' => 'test-mode-charge-123',
            'type' => 'payment.succeeded',
            'data' => [
                'reference' => $intent->reference,
                'transaction_id' => '123',
                'status' => 'succeeded',
                'amount_minor' => 12500,
                'currency' => 'NGN',
            ],
        ]);

        $this->assertSame('test_succeeded', $result['outcome']);
        $this->assertFalse($result['duplicate']);
        $this->assertSame('test_paid', $intent->fresh()->status);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);

        $duplicate = app(\App\Services\Payments\PaymentFundingService::class)->processVerifiedEvent('paystack', [
            'id' => 'test-mode-charge-123',
            'type' => 'payment.succeeded',
            'data' => [
                'reference' => $intent->reference,
                'transaction_id' => '123',
                'status' => 'succeeded',
                'amount_minor' => 12500,
                'currency' => 'NGN',
            ],
        ]);

        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
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
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack', 'live');

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
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack', 'live');

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
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack', 'live');

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
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack', 'live');

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
        $intent = app(PaymentIntentService::class)->create($wallet, 12500, 'paystack', 'live');

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
        $this->assertSame(0, (int) \App\Models\Wallet::query()->where('user_id', $user->id)->firstOrFail()->balance_minor);
    }

    public function test_successful_verified_browser_return_credits_wallet_once(): void
    {
        $user = User::query()->create([
            'name' => 'Return Customer',
            'email' => 'return@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 15000, 'paystack', 'live');

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

        $response = $this->actingAs($user)->get('/wallet/funding/return?reference='.$intent->reference);

        $response->assertRedirect(route('dashboard', ['funding' => 'success']));
        $this->assertSame(15000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);

        Http::assertSent(fn ($request): bool =>
            str_contains($request->url(), '/transaction/verify/')
            && $request->hasHeader('Authorization', 'Bearer sk_test_semizzy_test_secret')
        );
    }


    public function test_verified_paystack_success_settles_even_if_checkout_intent_expired(): void
    {
        $user = User::query()->create([
            'name' => 'Expired Intent Customer',
            'email' => 'expired-intent@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 15000, 'paystack', 'live');
        $intent->forceFill(['expires_at' => now()->subMinute()])->save();

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 889900,
                    'status' => 'success',
                    'reference' => $intent->reference,
                    'amount' => 15000,
                    'currency' => 'NGN',
                ],
            ], 200),
        ]);

        $url = '/wallet/funding/return?reference='.$intent->reference;
        $first = $this->actingAs($user)->get($url);
        $first->assertRedirect(route('dashboard', ['funding' => 'success']));

        $second = $this->get($url);
        $second->assertRedirect(route('dashboard', ['funding' => 'success']));

        $this->assertSame(15000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }


}
