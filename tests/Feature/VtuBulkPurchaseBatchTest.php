<?php

namespace Tests\Feature;

use App\Jobs\ProcessVtuBulkPurchaseBatch;
use App\Models\User;
use App\Models\VtuBulkPurchaseBatch;
use App\Models\VtuBulkPurchaseItem;
use App\Services\Vtu\VtuOrderService;
use Illuminate\Support\Carbon;
use App\Models\VtuProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VtuBulkPurchaseBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_request_persists_items_and_dispatches_one_durable_batch_job(): void
    {
        Queue::fake();
        [$user, $product, $payload] = $this->preparedRequest();
        $response = $this->actingAs($user)->postJson(route('wallet.vtu.orders.bulk'), $payload);

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.item_count', 10)
            ->assertJsonPath('data.total_amount_minor', 10000);

        $this->assertDatabaseCount('vtu_bulk_purchase_batches', 1);
        $this->assertDatabaseCount('vtu_bulk_purchase_items', 10);
        $this->assertDatabaseHas('vtu_bulk_purchase_items', [
            'batch_id' => VtuBulkPurchaseBatch::query()->sole()->id,
            'item_index' => 0,
            'status' => 'pending',
        ]);
        $this->assertStringNotContainsString($product->code, $response->getContent());
        Queue::assertPushed(ProcessVtuBulkPurchaseBatch::class, 1);
    }

    public function test_bulk_request_accepts_the_blueprint_maximum_of_100_items(): void
    {
        Queue::fake();
        [$user, , $payload] = $this->preparedRequest();
        $payload['batch_key'] = 'bulk-max-items-key-001';
        $payload['items'] = array_merge($payload['items'], array_fill(0, 90, $payload['items'][0]));

        $response = $this->actingAs($user)->postJson(route('wallet.vtu.orders.bulk'), $payload);

        $response->assertAccepted()
            ->assertJsonPath('data.item_count', 100)
            ->assertJsonPath('data.total_amount_minor', 100000);
        $this->assertDatabaseCount('vtu_bulk_purchase_items', 100);
        Queue::assertPushed(ProcessVtuBulkPurchaseBatch::class, 1);
    }

    public function test_bulk_request_rejects_more_than_100_items_before_persisting_any_batch(): void
    {
        Queue::fake();
        [$user, , $payload] = $this->preparedRequest();
        $payload['batch_key'] = 'bulk-over-limit-key-001';
        $payload['items'] = array_merge($payload['items'], array_fill(0, 91, $payload['items'][0]));

        $this->actingAs($user)->postJson(route('wallet.vtu.orders.bulk'), $payload)->assertUnprocessable();

        $this->assertDatabaseCount('vtu_bulk_purchase_batches', 0);
        $this->assertDatabaseCount('vtu_bulk_purchase_items', 0);
        Queue::assertNothingPushed();
    }

    public function test_same_batch_key_and_payload_returns_existing_batch_without_dispatching_again(): void
    {
        Queue::fake();
        [$user, , $payload] = $this->preparedRequest();

        $first = $this->actingAs($user)->postJson(route('wallet.vtu.orders.bulk'), $payload);
        $first->assertAccepted();
        $second = $this->actingAs($user)->postJson(route('wallet.vtu.orders.bulk'), $payload);

        $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('vtu_bulk_purchase_batches', 1);
        $this->assertDatabaseCount('vtu_bulk_purchase_items', 10);
        Queue::assertPushed(ProcessVtuBulkPurchaseBatch::class, 1);
    }

    public function test_reusing_batch_key_with_changed_items_is_rejected(): void
    {
        Queue::fake();
        [$user, , $payload] = $this->preparedRequest();
        $this->actingAs($user)->postJson(route('wallet.vtu.orders.bulk'), $payload)->assertAccepted();

        $payload['items'][0]['recipient'] = '08087654321';
        $this->actingAs($user)->postJson(route('wallet.vtu.orders.bulk'), $payload)->assertConflict();
        $this->assertDatabaseCount('vtu_bulk_purchase_batches', 1);
        $this->assertDatabaseCount('vtu_bulk_purchase_items', 10);
        Queue::assertPushed(ProcessVtuBulkPurchaseBatch::class, 1);
    }

    public function test_batch_can_be_recovered_by_its_stable_key_only_by_its_owner(): void
    {
        Queue::fake();
        [$user, , $payload] = $this->preparedRequest();
        $created = $this->actingAs($user)->postJson(route('wallet.vtu.orders.bulk'), $payload)->assertAccepted();
        $publicId = $created->json('data.id');

        $this->actingAs($user)->getJson(route('wallet.vtu.orders.bulk.recover', [
            'batch_key' => $payload['batch_key'],
        ]))->assertOk()->assertJsonPath('data.id', $publicId);

        $other = User::factory()->create();
        $this->actingAs($other)->getJson(route('wallet.vtu.orders.bulk.recover', [
            'batch_key' => $payload['batch_key'],
        ]))->assertNotFound();
    }

    public function test_batch_progress_is_only_visible_to_its_owner(): void
    {
        Queue::fake();
        [$user, , $payload] = $this->preparedRequest();
        $response = $this->actingAs($user)->postJson(route('wallet.vtu.orders.bulk'), $payload)->assertAccepted();
        $publicId = $response->json('data.id');

        $this->actingAs($user)->getJson(route('wallet.vtu.orders.bulk.status', $publicId))
            ->assertOk()->assertJsonPath('data.id', $publicId)->assertJsonCount(10, 'data.items');

        $other = User::factory()->create();
        $this->actingAs($other)->getJson(route('wallet.vtu.orders.bulk.status', $publicId))->assertNotFound();
    }


    public function test_bulk_rejects_bill_services_until_batch_verification_is_enabled(): void
    {
        Queue::fake();
        [$user, $product, $payload] = $this->preparedRequest();
        $product->forceFill([
            'service_type' => 'tv-subscription',
            'metadata' => ['purchase_supported' => true],
        ])->save();

        $response = $this->actingAs($user)->postJson(route('wallet.vtu.orders.bulk'), $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Item 1 is not an active airtime/data bulk selection. Other services remain unavailable for bulk purchase until their batch verification and recovery paths are enabled. No batch was accepted.');
        $this->assertDatabaseCount('vtu_bulk_purchase_batches', 0);
        $this->assertDatabaseCount('vtu_bulk_purchase_items', 0);
        Queue::assertNothingPushed();
    }

    public function test_uncertain_provider_outcome_is_recorded_as_unknown_and_not_retried_in_the_same_batch(): void
    {
        $user = User::factory()->create();
        $batch = VtuBulkPurchaseBatch::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'batch_key' => 'bulk-uncertain-key-001',
            'request_hash' => hash('sha256', 'uncertain-request'),
            'status' => 'queued',
            'item_count' => 1,
            'total_amount_minor' => 1000,
            'currency' => 'NGN',
        ]);
        $item = $batch->items()->create([
            'item_index' => 0,
            'idempotency_key' => hash('sha256', 'stable-uncertain-item-key'),
            'product_code' => 'internal-test-code',
            'service_type' => 'airtime',
            'recipient' => '08012345678',
            'amount_minor' => 1000,
            'status' => 'pending',
        ]);

        $orders = \Mockery::mock(\App\Services\Vtu\VtuOrderService::class);
        $orders->shouldReceive('purchase')
            ->once()
            ->withArgs(fn ($actualUser, $productCode, $recipient, $key) =>
                $actualUser->is($user)
                && $productCode === 'internal-test-code'
                && $recipient === '08012345678'
                && $key === $item->idempotency_key
            )
            ->andThrow(new \RuntimeException('Provider response timed out after submission'));

        (new ProcessVtuBulkPurchaseBatch((int) $batch->id))->handle($orders);

        $this->assertSame('unknown', $item->fresh()->status);
        $this->assertStringContainsString('Reconcile the existing idempotency reference', $item->fresh()->result_message);
        $this->assertSame('partial', $batch->fresh()->status);
        $orders->shouldHaveReceived('purchase')->once();
    }

    public function test_worker_does_not_reclaim_a_fresh_processing_item(): void
    {
        $user = User::factory()->create();
        $batch = VtuBulkPurchaseBatch::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'batch_key' => 'bulk-fresh-processing-key',
            'request_hash' => hash('sha256', 'fresh'),
            'status' => 'processing',
            'item_count' => 1,
            'total_amount_minor' => 1000,
            'currency' => 'NGN',
            'started_at' => now(),
        ]);
        $item = $batch->items()->create([
            'item_index' => 0,
            'idempotency_key' => hash('sha256', 'fresh-item'),
            'product_code' => 'private-code',
            'service_type' => 'airtime',
            'recipient' => '08012345678',
            'amount_minor' => 1000,
            'status' => 'processing',
            'started_at' => now(),
        ]);
        $orders = \Mockery::mock(VtuOrderService::class);
        $orders->shouldNotReceive('purchase');

        (new ProcessVtuBulkPurchaseBatch((int) $batch->id))->handle($orders);

        $this->assertSame('processing', $item->fresh()->status);
        $this->assertSame('processing', $batch->fresh()->status);
    }

    public function test_worker_marks_abandoned_processing_item_unknown_without_resubmitting(): void
    {
        $user = User::factory()->create();
        $batch = VtuBulkPurchaseBatch::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'batch_key' => 'bulk-stale-processing-key',
            'request_hash' => hash('sha256', 'stale'),
            'status' => 'processing',
            'item_count' => 1,
            'total_amount_minor' => 1000,
            'currency' => 'NGN',
            'started_at' => now()->subMinutes(31),
        ]);
        $item = $batch->items()->create([
            'item_index' => 0,
            'idempotency_key' => hash('sha256', 'stale-item'),
            'product_code' => 'private-code',
            'service_type' => 'airtime',
            'recipient' => '08012345678',
            'amount_minor' => 1000,
            'status' => 'processing',
            'started_at' => Carbon::now()->subMinutes(31),
        ]);
        $orders = \Mockery::mock(VtuOrderService::class);
        $orders->shouldNotReceive('purchase');

        (new ProcessVtuBulkPurchaseBatch((int) $batch->id))->handle($orders);

        $this->assertSame('unknown', $item->fresh()->status);
        $this->assertStringContainsString('Reconcile', $item->fresh()->result_message);
        $this->assertSame('partial', $batch->fresh()->status);
    }

    private function preparedRequest(): array
    {
        $user = User::factory()->create();
        $user->forceFill(['transaction_pin_hash' => Hash::make('1234')])->save();

        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'mtn',
            'code' => 'private-provider-airtime-'.uniqid(),
            'name' => 'MTN Airtime',
            'price_minor' => 1000,
            'face_value_minor' => 1000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);
        $id = (string) $product->getKey();
        $selectionCode = 'p'.base_convert($id, 10, 36).'.'.substr(
            hash_hmac('sha256', 'vtu-product:'.$id, (string) config('app.key')),
            0,
            32,
        );

        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = [
                'product_code' => $selectionCode,
                'recipient' => '08012345678',
                'amount' => 10,
            ];
        }

        return [$user, $product, [
            'batch_key' => 'bulk-test-key-001',
            'transaction_pin' => '1234',
            'items' => $items,
        ]];
    }
}
