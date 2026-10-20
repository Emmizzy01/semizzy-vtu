<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\PaymentIntentService;
use App\Services\Payments\PaymentFundingService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class PaymentIntentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_reuses_the_same_pending_intent_and_persists_gateway_mode_before_checkout(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'status' => 'active',
            'balance_minor' => 0,
            'available_balance_minor' => 0,
        ]);
        $service = app(PaymentIntentService::class);

        $first = $service->create($wallet, 25000, 'paystack', 'test', 'funding-request-123');
        $retry = $service->create($wallet->fresh(), 25000, 'paystack', 'test', 'funding-request-123');

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($first->reference, $retry->reference);
        $this->assertSame('test', $retry->fresh()->metadata['gateway_mode']);
        $this->assertArrayHasKey('client_idempotency_key_hash', $retry->fresh()->metadata);
        $this->assertNotSame('funding-request-123', $retry->fresh()->metadata['client_idempotency_key_hash']);
        $this->assertSame(1, PaymentIntent::query()->where('wallet_id', $wallet->id)->count());
    }

    public function test_retry_key_cannot_be_reused_with_a_different_amount(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'status' => 'active',
            'balance_minor' => 0,
            'available_balance_minor' => 0,
        ]);
        $service = app(PaymentIntentService::class);
        $service->create($wallet, 25000, 'paystack', 'test', 'funding-request-456');

        $this->expectException(InvalidArgumentException::class);
        $service->create($wallet->fresh(), 50000, 'paystack', 'test', 'funding-request-456');
    }

    public function test_retry_key_cannot_cross_gateway_modes(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'status' => 'active',
            'balance_minor' => 0,
            'available_balance_minor' => 0,
        ]);
        $service = app(PaymentIntentService::class);
        $service->create($wallet, 25000, 'paystack', 'test', 'funding-request-789');

        $this->expectException(InvalidArgumentException::class);
        $service->create($wallet->fresh(), 25000, 'paystack', 'live', 'funding-request-789');
    }

    public function test_verified_webhook_retry_is_idempotent_and_rejects_amount_mismatch_after_settlement(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'status' => 'active',
            'balance_minor' => 0,
            'available_balance_minor' => 0,
        ]);
        $intent = app(PaymentIntentService::class)->create($wallet, 25000, 'paystack', 'test', 'webhook-test-key');
        $funding = app(PaymentFundingService::class);
        $payload = [
            'id' => 'paystack-charge-success-9001',
            'type' => 'payment.succeeded',
            'data' => [
                'reference' => $intent->reference,
                'transaction_id' => '9001',
                'status' => 'succeeded',
                'amount_minor' => 25000,
                'currency' => 'NGN',
            ],
        ];

        $first = $funding->processVerifiedEvent('paystack', $payload);
        $retry = $funding->processVerifiedEvent('paystack', $payload);

        $this->assertSame('test_succeeded', $first['outcome']);
        $this->assertTrue($retry['duplicate']);
        $this->assertSame('test_paid', $intent->fresh()->status);

        $mismatched = $payload;
        $mismatched['id'] = 'paystack-charge-success-9002';
        $mismatched['data']['amount_minor'] = 50000;

        $this->expectException(ConflictHttpException::class);
        $funding->processVerifiedEvent('paystack', $mismatched);
    }


    public function test_live_funding_settlement_is_blocked_until_release_gate_is_open(): void
    {
        config(['services.semizzy_payments.live_funding_enabled' => false]);

        $user = User::factory()->create();
        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'status' => 'active',
            'balance_minor' => 0,
            'available_balance_minor' => 0,
        ]);
        $intent = app(PaymentIntentService::class)->create($wallet, 25000, 'paystack', 'live', 'live-funding-gate-key');

        $this->expectException(LogicException::class);
        app(PaymentFundingService::class)->processVerifiedEvent('paystack', [
            'id' => 'paystack-live-charge-1001',
            'type' => 'payment.succeeded',
            'data' => [
                'reference' => $intent->reference,
                'transaction_id' => '1001',
                'status' => 'succeeded',
                'amount_minor' => 25000,
                'currency' => 'NGN',
            ],
        ]);
    }

}
