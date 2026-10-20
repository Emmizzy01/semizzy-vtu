<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\PaymentIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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
}
