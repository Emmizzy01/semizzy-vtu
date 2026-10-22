<?php

namespace Tests\Feature;

use App\Models\PlatformAddon;
use App\Models\User;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Models\VtuProviderWebhookEvent;
use App\Services\Vtu\VtuOrderService;
use App\Services\Vtu\VtuProviderWebhookService;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class VtuProviderWebhookRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unexpected_order_service_exception_is_recorded_and_does_not_abort_the_batch(): void
    {
        $order = $this->createMatchedOrder('SOGO-EXCEPTION-1');
        $failedEvent = $this->createEvent('evt-exception-1', 'SOGO-EXCEPTION-1');
        $unmatchedEvent = $this->createEvent('evt-unmatched-after-exception', 'SOGO-NOT-PERSISTED-2');

        $this->mock(VtuOrderService::class, function ($mock): void {
            $mock->shouldReceive('applyVerifiedWebhookResult')
                ->once()
                ->andThrow(new RuntimeException('Simulated transient order service failure.'));
        });

        $stats = app(VtuProviderWebhookService::class)->process();

        $this->assertSame(2, $stats['checked']);
        $this->assertSame(1, $stats['unmatched']);
        $this->assertSame(0, $stats['processed']);
        $this->assertNull($failedEvent->fresh()->processed_at);
        $this->assertSame(
            'Webhook processing failed unexpectedly; retry scheduled after backoff.',
            $failedEvent->fresh()->processing_error,
        );
        $this->assertNull($unmatchedEvent->fresh()->processed_at);
        $this->assertStringContainsString(
            'Waiting for the purchase response',
            $unmatchedEvent->fresh()->processing_error,
        );
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_sogo_event_and_embedded_status_must_agree_before_order_update(): void
    {
        $order = $this->createMatchedOrder('SOGO-CONTRADICTORY-1');
        $event = $this->createEvent('evt-contradictory-1', 'SOGO-CONTRADICTORY-1');
        $event->forceFill([
            'payload' => [
                'id' => 'evt-contradictory-1',
                'event' => 'transaction.completed',
                'data' => ['reference' => 'SOGO-CONTRADICTORY-1', 'status' => 'failed'],
            ],
        ])->save();

        $stats = app(VtuProviderWebhookService::class)->process();

        $this->assertSame(1, $stats['checked']);
        $this->assertSame(1, $stats['ignored']);
        $this->assertSame(0, $stats['processed']);
        $this->assertNotNull($event->fresh()->processed_at);
        $this->assertStringContainsString('Ignored unsupported webhook', $event->fresh()->processing_error);
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_worker_skips_event_already_claimed_within_backoff_window(): void
    {
        $this->createMatchedOrder('SOGO-COMPETING-1');
        $event = $this->createEvent('evt-competing-1', 'SOGO-COMPETING-1');
        $event->forceFill(['last_attempted_at' => now()])->save();

        $this->mock(VtuOrderService::class, function ($mock): void {
            $mock->shouldNotReceive('applyVerifiedWebhookResult');
        });

        $stats = app(VtuProviderWebhookService::class)->process();

        $this->assertSame(0, $stats['checked']);
        $this->assertSame(0, $stats['processed']);
        $this->assertNull($event->fresh()->processed_at);
        $this->assertNotNull($event->fresh()->last_attempted_at);
    }

    public function test_competing_webhook_worker_cannot_process_event_while_first_worker_holds_claim(): void
    {
        $this->createMatchedOrder('SOGO-WEBHOOK-COMPETING-1');
        $event = $this->createEvent('evt-webhook-competing-1', 'SOGO-WEBHOOK-COMPETING-1');
        $nestedStats = null;

        $this->mock(VtuOrderService::class, function ($mock) use (&$nestedStats): void {
            $mock->shouldReceive('applyVerifiedWebhookResult')
                ->once()
                ->andReturnUsing(function (VtuOrder $order) use (&$nestedStats): VtuOrder {
                    // Simulate another scheduler/queue worker entering while the
                    // first worker is processing the event it has already claimed.
                    $nestedStats = app(VtuProviderWebhookService::class)->process();

                    return $order;
                });
        });

        $stats = app(VtuProviderWebhookService::class)->process();
        $this->assertSame(1, $stats['checked']);
        $this->assertSame(1, $stats['processed']);
        $this->assertIsArray($nestedStats);
        $this->assertSame(0, $nestedStats['checked']);
        $this->assertSame(0, $nestedStats['processed']);
        $this->assertNotNull($event->fresh()->processed_at);
    }

    public function test_webhook_processing_retries_after_worker_failure_and_backoff(): void
    {
        $this->createMatchedOrder('SOGO-RETRY-AFTER-CRASH-1');
        $event = $this->createEvent('evt-retry-after-crash-1', 'SOGO-RETRY-AFTER-CRASH-1');
        $calls = 0;

        $this->mock(VtuOrderService::class, function ($mock) use (&$calls): void {
            $mock->shouldReceive('applyVerifiedWebhookResult')
                ->twice()
                ->andReturnUsing(function (VtuOrder $order) use (&$calls): VtuOrder {
                    $calls++;
                    if ($calls === 1) {
                        throw new RuntimeException('Simulated worker crash before event acknowledgement.');
                    }

                    return $order;
                });
        });

        try {
            $first = app(VtuProviderWebhookService::class)->process();
            $this->assertSame(0, $first['processed']);
            $this->assertNull($event->fresh()->processed_at);
            $this->assertNotNull($event->fresh()->last_attempted_at);

            // Model a worker restart after the documented five-minute retry backoff.
            Carbon::setTestNow(now()->addMinutes(6));
            $second = app(VtuProviderWebhookService::class)->process();

            $this->assertSame(1, $second['checked']);
            $this->assertSame(1, $second['processed']);
            $this->assertNotNull($event->fresh()->processed_at);
            $this->assertNull($event->fresh()->processing_error);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_processed_webhook_duplicate_delivery_is_not_applied_twice(): void
    {
        $this->createMatchedOrder('SOGO-DUPLICATE-DELIVERY-1');
        $event = $this->createEvent('evt-duplicate-delivery-1', 'SOGO-DUPLICATE-DELIVERY-1');

        $this->mock(VtuOrderService::class, function ($mock): void {
            $mock->shouldReceive('applyVerifiedWebhookResult')->once()->andReturnUsing(
                fn (VtuOrder $order): VtuOrder => $order,
            );
        });

        $first = app(VtuProviderWebhookService::class)->process();
        $second = app(VtuProviderWebhookService::class)->process();

        $this->assertSame(1, $first['processed']);
        $this->assertSame(0, $second['checked']);
        $this->assertSame(0, $second['processed']);
        $this->assertNotNull($event->fresh()->processed_at);
    }


    public function test_accepted_order_webhook_recovery_is_not_blocked_when_service_addon_is_disabled(): void
    {
        $order = $this->createMatchedOrder('SOGO-ACCEPTED-WHILE-PAUSED-1');
        $event = $this->createEvent('evt-accepted-while-paused-1', 'SOGO-ACCEPTED-WHILE-PAUSED-1');

        PlatformAddon::query()->create([
            'addon_key' => 'service.airtime',
            'name' => 'Airtime',
            'version' => '1.0.0',
            'minimum_core_version' => '1.0.0',
            'status' => 'disabled',
            'is_core_owned' => false,
            'compatibility_status' => 'incompatible',
            'compatibility_message' => 'Test: new purchases are paused.',
        ]);

        // Lifecycle admission controls new submissions only. A verified callback
        // for an already accepted provider order must still reach order recovery.
        $this->mock(VtuOrderService::class, function ($mock) use ($order): void {
            $mock->shouldReceive('applyVerifiedWebhookResult')
                ->once()
                ->withArgs(fn (VtuOrder $actual, string $provider, string $reference, array $result): bool =>
                    $actual->is($order)
                    && $provider === 'sogo'
                    && $reference === 'SOGO-ACCEPTED-WHILE-PAUSED-1'
                    && $result['status'] === 'success'
                )
                ->andReturnUsing(fn (VtuOrder $actual): VtuOrder => $actual);
        });

        $stats = app(VtuProviderWebhookService::class)->process();

        $this->assertSame(1, $stats['processed']);
        $this->assertNotNull($event->fresh()->processed_at);
        $this->assertSame('processing', $order->fresh()->status);
    }

    private function createMatchedOrder(string $providerReference): VtuOrder
    {
        $user = User::factory()->create();
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'mtn',
            'code' => 'webhook-test-'.Str::uuid(),
            'name' => 'Webhook Test Airtime',
            'price_minor' => 1000,
            'face_value_minor' => 1000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        return VtuOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'wallet_id' => $wallet->getKey(),
            'vtu_product_id' => $product->getKey(),
            'idempotency_key' => 'webhook-test-'.Str::uuid(),
            'request_hash' => hash('sha256', $providerReference),
            'service_type' => 'airtime',
            'network' => 'mtn',
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'amount_minor' => 1000,
            'currency' => 'NGN',
            'status' => 'processing',
            'provider' => 'sogo',
            'provider_reference' => $providerReference,
        ]);
    }

    private function createEvent(string $eventId, string $providerReference): VtuProviderWebhookEvent
    {
        return VtuProviderWebhookEvent::query()->create([
            'provider' => 'sogo',
            'event_id' => $eventId,
            'event_type' => 'transaction.completed',
            'provider_reference' => $providerReference,
            'payload' => [
                'id' => $eventId,
                'event' => 'transaction.completed',
                'data' => ['reference' => $providerReference, 'status' => 'completed'],
            ],
            'payload_sha256' => hash('sha256', $eventId),
            'received_at' => now(),
        ]);
    }
}
