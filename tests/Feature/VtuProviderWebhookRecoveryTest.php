<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Models\VtuProviderWebhookEvent;
use App\Services\Vtu\VtuOrderService;
use App\Services\Vtu\VtuProviderWebhookService;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                ->andReturnUsing(function () use (&$nestedStats): void {
                    // Simulate another scheduler/queue worker entering while the
                    // first worker is processing the event it has already claimed.
                    $nestedStats = app(VtuProviderWebhookService::class)->process();
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
