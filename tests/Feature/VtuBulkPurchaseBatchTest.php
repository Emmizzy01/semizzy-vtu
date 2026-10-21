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
use Illuminate\Database\QueryException;
use ReflectionMethod;
use Tests\TestCase;

class VtuBulkPurchaseBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_key_collision_detector_accepts_only_matching_unique_constraint_errors(): void
    {
        $controller = app(\App\Http\Controllers\Vtu\VtuOrderController::class);
        $method = new ReflectionMethod($controller, 'isBatchKeyUniqueConstraintViolation');

        $batchPrevious = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: vtu_bulk_purchase_batches.user_id, vtu_bulk_purchase_batches.batch_key');
        $batchPrevious->errorInfo = ['23000', 19, 'UNIQUE constraint failed'];
        $userPrevious = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.email');
        $userPrevious->errorInfo = ['23000', 19, 'UNIQUE constraint failed'];
        $databasePrevious = new \PDOException('SQLSTATE[HY000]: General error: 1 no such table: vtu_bulk_purchase_batches');
        $databasePrevious->errorInfo = ['HY000', 1, 'no such table'];
        $mysqlPrevious = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-bulk-key' for key 'vtu_bulk_purchase_batches_user_id_batch_key_unique'");
        $mysqlPrevious->errorInfo = ['23000', 1062, 'Duplicate entry for key'];
        $postgresPrevious = new \PDOException('SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "vtu_bulk_purchase_batches_user_id_batch_key_unique" DETAIL: Key (user_id, batch_key)=(1, bulk-key) already exists.');
        $postgresPrevious->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint'];


        $batchCollision = new QueryException(
            'sqlite',
            'insert into vtu_bulk_purchase_batches (user_id, batch_key) values (?, ?)',
            [],
            $batchPrevious
        );
        $mysqlBatchCollision = new QueryException(
            'mysql',
            'insert into vtu_bulk_purchase_batches (user_id, batch_key) values (?, ?)',
            [],
            $mysqlPrevious
        );
        $postgresBatchCollision = new QueryException(
            'pgsql',
            'insert into vtu_bulk_purchase_batches (user_id, batch_key) values (?, ?)',
            [],
            $postgresPrevious
        );
        $otherUniqueCollision = new QueryException(
            'sqlite',
            'insert into users (email) values (?)',
            [],
            $userPrevious
        );
        $otherDatabaseFailure = new QueryException(
            'sqlite',
            'insert into vtu_bulk_purchase_batches (user_id, batch_key) values (?, ?)',
            [],
            $databasePrevious
        );

        $this->assertTrue($method->invoke($controller, $batchCollision));
        $this->assertTrue($method->invoke($controller, $mysqlBatchCollision));
        $this->assertTrue($method->invoke($controller, $postgresBatchCollision));
        $this->assertFalse($method->invoke($controller, $otherUniqueCollision));
        $this->assertFalse($method->invoke($controller, $otherDatabaseFailure));
    }

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

    public function test_different_users_can_use_the_same_batch_key_without_cross_account_reuse(): void
    {
        Queue::fake();
        [$firstUser, , $firstPayload] = $this->preparedRequest();
        [$secondUser, , $secondPayload] = $this->preparedRequest();
        $firstPayload['batch_key'] = 'shared-bulk-key-across-users';
        $secondPayload['batch_key'] = 'shared-bulk-key-across-users';

        $first = $this->actingAs($firstUser)
            ->postJson(route('wallet.vtu.orders.bulk'), $firstPayload)
            ->assertAccepted();
        $second = $this->actingAs($secondUser)
            ->postJson(route('wallet.vtu.orders.bulk'), $secondPayload)
            ->assertAccepted();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('vtu_bulk_purchase_batches', 2);
        $this->assertDatabaseCount('vtu_bulk_purchase_items', 20);
        $this->assertDatabaseHas('vtu_bulk_purchase_batches', [
            'public_id' => $first->json('data.id'),
            'user_id' => $firstUser->id,
            'batch_key' => 'shared-bulk-key-across-users',
        ]);
        $this->assertDatabaseHas('vtu_bulk_purchase_batches', [
            'public_id' => $second->json('data.id'),
            'user_id' => $secondUser->id,
            'batch_key' => 'shared-bulk-key-across-users',
        ]);
        Queue::assertPushed(ProcessVtuBulkPurchaseBatch::class, 2);
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

    public function test_failed_worker_marks_in_flight_item_unknown_without_automatic_resubmission(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $batch = VtuBulkPurchaseBatch::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'batch_key' => 'bulk-worker-interrupted-key',
            'request_hash' => hash('sha256', 'worker-interrupted'),
            'status' => 'processing',
            'item_count' => 1,
            'total_amount_minor' => 1000,
            'currency' => 'NGN',
            'started_at' => now()->subMinutes(31),
        ]);
        $item = $batch->items()->create([
            'item_index' => 0,
            'idempotency_key' => hash('sha256', 'interrupted-item-key'),
            'product_code' => 'private-provider-code',
            'service_type' => 'airtime',
            'recipient' => '08012345678',
            'amount_minor' => 1000,
            'status' => 'processing',
            'started_at' => now()->subMinutes(31),
        ]);

        (new ProcessVtuBulkPurchaseBatch((int) $batch->id))->failed(new \RuntimeException('Worker terminated'));

        $this->assertSame('unknown', $item->fresh()->status);
        $this->assertStringContainsString('Reconcile', $item->fresh()->result_message);
        $this->assertSame('partial', $batch->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_failed_worker_marks_in_flight_unknown_and_queues_only_remaining_pending_items(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $batch = VtuBulkPurchaseBatch::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'batch_key' => 'bulk-worker-remainder-key',
            'request_hash' => hash('sha256', 'worker-remainder'),
            'status' => 'processing',
            'item_count' => 2,
            'total_amount_minor' => 2000,
            'currency' => 'NGN',
            'started_at' => now()->subMinutes(31),
        ]);
        $inFlight = $batch->items()->create([
            'item_index' => 0,
            'idempotency_key' => hash('sha256', 'worker-remainder-in-flight'),
            'product_code' => 'private-provider-code-a',
            'service_type' => 'airtime',
            'recipient' => '08012345678',
            'amount_minor' => 1000,
            'status' => 'processing',
            'started_at' => now()->subMinutes(31),
        ]);
        $pending = $batch->items()->create([
            'item_index' => 1,
            'idempotency_key' => hash('sha256', 'worker-remainder-pending'),
            'product_code' => 'private-provider-code-b',
            'service_type' => 'airtime',
            'recipient' => '08087654321',
            'amount_minor' => 1000,
            'status' => 'pending',
        ]);

        (new ProcessVtuBulkPurchaseBatch((int) $batch->id))->failed(new \RuntimeException('Worker terminated'));

        $this->assertSame('unknown', $inFlight->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('processing', $batch->fresh()->status);
        Queue::assertPushed(ProcessVtuBulkPurchaseBatch::class, 1);
    }

    public function test_failed_worker_does_not_mark_a_fresh_item_owned_by_another_worker_unknown(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $batch = VtuBulkPurchaseBatch::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'batch_key' => 'bulk-worker-concurrent-key',
            'request_hash' => hash('sha256', 'worker-concurrent'),
            'status' => 'processing',
            'item_count' => 2,
            'total_amount_minor' => 2000,
            'currency' => 'NGN',
            'started_at' => now(),
        ]);
        $active = $batch->items()->create([
            'item_index' => 0,
            'idempotency_key' => hash('sha256', 'worker-concurrent-active'),
            'product_code' => 'private-provider-code-active',
            'service_type' => 'airtime',
            'recipient' => '08012345678',
            'amount_minor' => 1000,
            'status' => 'processing',
            'started_at' => now(),
        ]);
        $pending = $batch->items()->create([
            'item_index' => 1,
            'idempotency_key' => hash('sha256', 'worker-concurrent-pending'),
            'product_code' => 'private-provider-code-pending',
            'service_type' => 'airtime',
            'recipient' => '08087654321',
            'amount_minor' => 1000,
            'status' => 'pending',
        ]);

        (new ProcessVtuBulkPurchaseBatch((int) $batch->id))->failed(new \RuntimeException('Duplicate worker terminated'));

        $this->assertSame('processing', $active->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('processing', $batch->fresh()->status);
        Queue::assertPushed(ProcessVtuBulkPurchaseBatch::class, 1);
    }

    public function test_duplicate_job_delivery_does_not_resubmit_an_item_with_uncertain_outcome(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $batch = VtuBulkPurchaseBatch::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'batch_key' => 'bulk-duplicate-delivery-key',
            'request_hash' => hash('sha256', 'duplicate-delivery'),
            'status' => 'partial',
            'item_count' => 1,
            'total_amount_minor' => 1000,
            'currency' => 'NGN',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $item = $batch->items()->create([
            'item_index' => 0,
            'idempotency_key' => hash('sha256', 'duplicate-delivery-item'),
            'product_code' => 'private-provider-code',
            'service_type' => 'airtime',
            'recipient' => '08012345678',
            'amount_minor' => 1000,
            'status' => 'unknown',
            'result_message' => 'Reconcile before retrying.',
            'completed_at' => now(),
        ]);
        $orders = \Mockery::mock(VtuOrderService::class);
        $orders->shouldNotReceive('purchase');

        (new ProcessVtuBulkPurchaseBatch((int) $batch->id))->handle($orders);

        $this->assertSame('unknown', $item->fresh()->status);
        $this->assertSame('partial', $batch->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_fresh_in_flight_items_do_not_starve_pending_items_beyond_the_worker_limit(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $batch = VtuBulkPurchaseBatch::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'batch_key' => 'bulk-worker-starvation-key',
            'request_hash' => hash('sha256', 'worker-starvation'),
            'status' => 'processing',
            'item_count' => 11,
            'total_amount_minor' => 11000,
            'currency' => 'NGN',
            'started_at' => now(),
        ]);

        $inFlightItems = [];
        for ($i = 0; $i < 10; $i++) {
            $inFlightItems[] = $batch->items()->create([
                'item_index' => $i,
                'idempotency_key' => hash('sha256', 'worker-starvation-active-'.$i),
                'product_code' => 'private-active-code-'.$i,
                'service_type' => 'airtime',
                'recipient' => '08012345678',
                'amount_minor' => 1000,
                'status' => 'processing',
                'started_at' => now(),
            ]);
        }

        $pending = $batch->items()->create([
            'item_index' => 10,
            'idempotency_key' => hash('sha256', 'worker-starvation-pending'),
            'product_code' => 'private-pending-code',
            'service_type' => 'airtime',
            'recipient' => '08087654321',
            'amount_minor' => 1000,
            'status' => 'pending',
        ]);

        $orders = \Mockery::mock(VtuOrderService::class);
        $orders->shouldReceive('purchase')
            ->once()
            ->withArgs(fn ($actualUser, $productCode, $recipient, $key) =>
                $actualUser->is($user)
                && $productCode === 'private-pending-code'
                && $recipient === '08087654321'
                && $key === $pending->idempotency_key
            )
            ->andThrow(new \RuntimeException('Provider outcome unknown'));

        (new ProcessVtuBulkPurchaseBatch((int) $batch->id))->handle($orders);

        foreach ($inFlightItems as $activeItem) {
            $this->assertSame('processing', $activeItem->fresh()->status);
        }
        $this->assertSame('unknown', $pending->fresh()->status);
        // Other fresh in-flight items keep the overall batch processing until they resolve.
        $this->assertSame('processing', $batch->fresh()->status);
        $orders->shouldHaveReceived('purchase')->once();
        Queue::assertNothingPushed();
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

    public function test_late_worker_cannot_overwrite_item_quarantined_as_unknown(): void
    {
        $user = User::factory()->create();
        $batch = VtuBulkPurchaseBatch::query()->create([
            'public_id' => (string) \\Illuminate\\Support\\Str::uuid(),
            'user_id' => $user->id,
            'batch_key' => 'bulk-late-worker-quarantine-key',
            'request_hash' => hash('sha256', 'late-worker-quarantine'),
            'status' => 'processing',
            'item_count' => 1,
            'total_amount_minor' => 1000,
            'currency' => 'NGN',
        ]);
        $item = $batch->items()->create([
            'item_index' => 0,
            'idempotency_key' => hash('sha256', 'late-worker-quarantine-item'),
            'product_code' => 'private-code',
            'service_type' => 'airtime',
            'recipient' => '08012345678',
            'amount_minor' => 1000,
            'status' => 'unknown',
            'result_message' => 'Stale in-flight item quarantined for reconciliation.',
            'completed_at' => now(),
        ]);

        $job = new ProcessVtuBulkPurchaseBatch((int) $batch->id);
        $method = new \\ReflectionMethod($job, 'finalizeClaimedItem');
        $updated = $method->invoke($job, (int) $item->id, [
            'status' => 'successful',
            'result_message' => null,
            'completed_at' => now(),
        ]);

        $this->assertFalse($updated);
        $this->assertSame('unknown', $item->fresh()->status);
        $this->assertSame(
            'Stale in-flight item quarantined for reconciliation.',
            $item->fresh()->result_message,
        );
    }

}
