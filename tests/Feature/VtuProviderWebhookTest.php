<?php

namespace Tests\Feature;

use App\Models\VtuProviderWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VtuProviderWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.vtu.sogo.webhook_secret' => 'sogo-webhook-test-secret',
            'services.vtu.dataway.webhook_secret' => 'dataway-webhook-test-secret',
        ]);
    }

    public function test_sogo_webhook_requires_signature_and_stores_event_idempotently(): void
    {
        $body = json_encode(['id' => 'evt-sogo-1', 'event' => 'transaction.completed',
            'data' => ['reference' => 'SOGO-TXN-1', 'status' => 'completed']], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'sogo-webhook-test-secret');

        $this->call('POST', '/api/vtu/webhooks/sogo', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SOGO_SIGNATURE_256' => $signature,
        ], $body)->assertStatus(202)->assertJson(['accepted' => true, 'duplicate' => false]);

        $this->call('POST', '/api/vtu/webhooks/sogo', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SOGO_SIGNATURE_256' => $signature,
        ], $body)->assertStatus(202)->assertJson(['accepted' => true, 'duplicate' => true]);

        $this->assertSame(1, VtuProviderWebhookEvent::query()->where('provider', 'sogo')->count());
    }

    public function test_invalid_signature_is_rejected_without_storing_event(): void
    {
        $body = json_encode(['id' => 'evt-bad', 'event' => 'transaction.completed',
            'data' => ['reference' => 'SOGO-TXN-2', 'status' => 'completed']], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/vtu/webhooks/sogo', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SOGO_SIGNATURE_256' => 'sha256='.str_repeat('0', 64),
        ], $body)->assertUnauthorized();

        $this->assertDatabaseCount('vtu_provider_webhook_events', 0);
    }

    public function test_reused_sogo_event_id_with_different_body_is_rejected(): void
    {
        $body = json_encode(['id' => 'evt-conflict', 'event' => 'transaction.completed',
            'data' => ['reference' => 'SOGO-TXN-3', 'status' => 'completed']], JSON_THROW_ON_ERROR);
        $changed = json_encode(['id' => 'evt-conflict', 'event' => 'transaction.failed',
            'data' => ['reference' => 'SOGO-TXN-3', 'status' => 'failed']], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/vtu/webhooks/sogo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SOGO_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'sogo-webhook-test-secret'),
        ], $body)->assertStatus(202);

        $this->call('POST', '/api/vtu/webhooks/sogo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SOGO_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $changed, 'sogo-webhook-test-secret'),
        ], $changed)->assertStatus(409);

        $this->assertDatabaseCount('vtu_provider_webhook_events', 1);
    }

    public function test_dataway_webhook_is_verified_and_deduplicated_by_raw_payload_hash(): void
    {
        $body = json_encode(['event' => 'transaction.successful',
            'data' => ['reference' => 'DATAWAY-TXN-1', 'status' => 'successful']], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'dataway-webhook-test-secret');

        $this->call('POST', '/api/vtu/webhooks/dataway', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_DATAWAY_SIGNATURE' => $signature,
        ], $body)->assertStatus(202)->assertJson(['accepted' => true, 'duplicate' => false]);

        $this->call('POST', '/api/vtu/webhooks/dataway', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_DATAWAY_SIGNATURE' => $signature,
        ], $body)->assertStatus(202)->assertJson(['accepted' => true, 'duplicate' => true]);

        $this->assertSame(1, VtuProviderWebhookEvent::query()->where('provider', 'dataway')->count());
    }

    public function test_unmatched_verified_webhook_retries_after_backoff_without_being_marked_processed(): void
    {
        $event = VtuProviderWebhookEvent::query()->create([
            'provider' => 'sogo',
            'event_id' => 'evt-unmatched-retry',
            'event_type' => 'transaction.completed',
            'provider_reference' => 'SOGO-ORDER-NOT-PERSISTED-YET',
            'payload' => [
                'id' => 'evt-unmatched-retry',
                'event' => 'transaction.completed',
                'data' => ['reference' => 'SOGO-ORDER-NOT-PERSISTED-YET', 'status' => 'completed'],
            ],
            'payload_sha256' => hash('sha256', 'evt-unmatched-retry'),
            'received_at' => now(),
        ]);

        $first = app(\App\Services\Vtu\VtuProviderWebhookService::class)->process();
        $this->assertSame(1, $first['unmatched']);
        $this->assertNull($event->fresh()->processed_at);
        $this->assertStringContainsString('Waiting for the purchase response', $event->fresh()->processing_error);

        $immediate = app(\App\Services\Vtu\VtuProviderWebhookService::class)->process();
        $this->assertSame(0, $immediate['checked']);

        $this->travel(6)->minutes();
        $retried = app(\App\Services\Vtu\VtuProviderWebhookService::class)->process();

        $this->assertSame(1, $retried['unmatched']);
        $this->assertNull($event->fresh()->processed_at);
    }

}
