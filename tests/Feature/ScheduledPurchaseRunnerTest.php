<?php

namespace Tests\Feature;

use App\Models\ScheduledServicePurchase;
use App\Models\User;
use App\Models\VtuOrder;
use App\Services\Vtu\VtuOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduledPurchaseRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_schedule_uses_stable_idempotency_key_and_advances_after_confirmed_result(): void
    {
        $user = User::query()->create([
            'name' => 'Scheduled Runner',
            'email' => 'scheduled-runner@example.test',
            'password' => 'not-a-real-password',
        ]);
        $dueAt = now()->subMinutes(2)->startOfMinute();
        $schedule = ScheduledServicePurchase::query()->create([
            'user_id' => $user->id,
            'service_type' => 'data',
            'product_code' => 'runner-plan',
            'recipient' => '08012345678',
            'frequency' => 'weekly',
            'next_run_at' => $dueAt,
            'status' => 'active',
        ]);

        $order = new VtuOrder();
        $order->forceFill(['public_id' => 'order-runner-success', 'status' => 'successful']);

        $this->mock(VtuOrderService::class)
            ->shouldReceive('purchase')
            ->once()
            ->withArgs(function ($actualUser, $productCode, $recipient, $key, $details, $amount) use ($user, $schedule, $dueAt): bool {
                return $actualUser->is($user)
                    && $productCode === 'runner-plan'
                    && $recipient === '08012345678'
                    && $key === 'scheduled:'.$schedule->id.':'.$dueAt->timestamp
                    && $details === []
                    && $amount === null;
            })
            ->andReturn($order);

        $this->artisan('vtu:run-scheduled-purchases --limit=100')->assertExitCode(0);

        $schedule->refresh();
        $this->assertSame('active', $schedule->status);
        $this->assertSame('order-runner-success', $schedule->last_order_public_id);
        $this->assertSame('successful', $schedule->last_result);
        $this->assertTrue($schedule->next_run_at->greaterThan(now()));
        $this->assertNull($schedule->last_error);
    }

    public function test_due_schedule_pauses_when_provider_outcome_is_not_final(): void
    {
        $user = User::query()->create([
            'name' => 'Scheduled Unknown',
            'email' => 'scheduled-unknown@example.test',
            'password' => 'not-a-real-password',
        ]);
        $schedule = ScheduledServicePurchase::query()->create([
            'user_id' => $user->id,
            'service_type' => 'data',
            'product_code' => 'runner-unknown-plan',
            'recipient' => '08012345678',
            'frequency' => 'daily',
            'next_run_at' => now()->subMinutes(2),
            'status' => 'active',
        ]);

        $order = new VtuOrder();
        $order->forceFill(['public_id' => 'order-runner-unknown', 'status' => 'processing']);

        $this->mock(VtuOrderService::class)
            ->shouldReceive('purchase')
            ->once()
            ->andReturn($order);

        $this->artisan('vtu:run-scheduled-purchases --limit=100')->assertExitCode(0);

        $schedule->refresh();
        $this->assertSame('paused', $schedule->status);
        $this->assertSame('order-runner-unknown', $schedule->last_order_public_id);
        $this->assertSame('processing', $schedule->last_result);
        $this->assertStringContainsString('paused until the existing order is reconciled', (string) $schedule->last_error);
    }
    public function test_abandoned_processing_schedule_is_paused_without_replaying_purchase(): void
    {
        $user = User::query()->create([
            'name' => 'Stale Scheduled User',
            'email' => 'stale-scheduled@example.test',
            'password' => 'not-a-real-password',
        ]);
        $schedule = ScheduledServicePurchase::query()->create([
            'user_id' => $user->id,
            'service_type' => 'data',
            'product_code' => 'stale-runner-plan',
            'recipient' => '08012345678',
            'frequency' => 'daily',
            'next_run_at' => now()->subMinutes(45),
            'status' => 'processing',
        ]);
        \Illuminate\Support\Facades\DB::table('scheduled_service_purchases')
            ->where('id', $schedule->id)
            ->update(['updated_at' => now()->subMinutes(31)]);

        $this->mock(VtuOrderService::class)->shouldNotReceive('purchase');

        $this->artisan('vtu:run-scheduled-purchases --limit=100')->assertExitCode(0);

        $schedule->refresh();
        $this->assertSame('paused', $schedule->status);
        $this->assertSame('unknown', $schedule->last_result);
        $this->assertStringContainsString('Check the stable scheduled idempotency reference', (string) $schedule->last_error);
    }


    public function test_runner_does_not_reactivate_schedule_paused_during_provider_request(): void
    {
        $user = User::query()->create([
            'name' => 'Paused During Provider Call',
            'email' => 'paused-during-provider@example.test',
            'password' => 'not-a-real-password',
        ]);
        $schedule = ScheduledServicePurchase::query()->create([
            'user_id' => $user->id,
            'service_type' => 'data',
            'product_code' => 'paused-during-provider-plan',
            'recipient' => '08012345678',
            'frequency' => 'daily',
            'next_run_at' => now()->subMinutes(2),
            'status' => 'active',
        ]);

        $order = new VtuOrder();
        $order->forceFill(['public_id' => 'order-finished-after-pause', 'status' => 'successful']);

        $this->mock(VtuOrderService::class)
            ->shouldReceive('purchase')
            ->once()
            ->andReturnUsing(function () use ($schedule, $order) {
                // Simulate stale-claim recovery or an operator pausing the schedule
                // while the external provider request is still in flight.
                \Illuminate\\Support\\Facades\\DB::table('scheduled_service_purchases')
                    ->where('id', $schedule->id)
                    ->update(['status' => 'paused']);

                return $order;
            });

        $this->artisan('vtu:run-scheduled-purchases --limit=100')->assertExitCode(0);

        $schedule->refresh();
        $this->assertSame('paused', $schedule->status);
        $this->assertSame('order-finished-after-pause', $schedule->last_order_public_id);
        $this->assertSame('successful', $schedule->last_result);
        $this->assertStringContainsString('after this schedule was paused', (string) $schedule->last_error);
    }

    public function test_provider_exception_pauses_schedule_and_never_replays_uncertain_purchase(): void
    {
        $user = User::query()->create([
            'name' => 'Interrupted Scheduled User',
            'email' => 'interrupted-scheduled@example.test',
            'password' => 'not-a-real-password',
        ]);
        $schedule = ScheduledServicePurchase::query()->create([
            'user_id' => $user->id,
            'service_type' => 'data',
            'product_code' => 'interrupted-plan',
            'recipient' => '08012345678',
            'frequency' => 'daily',
            'next_run_at' => now()->subMinutes(2),
            'status' => 'active',
        ]);

        $this->mock(VtuOrderService::class)
            ->shouldReceive('purchase')
            ->once()
            ->andThrow(new \RuntimeException('Simulated transport interruption after dispatch began.'));

        $this->artisan('vtu:run-scheduled-purchases --limit=100')->assertExitCode(0);

        $schedule->refresh();
        $this->assertSame('paused', $schedule->status);
        $this->assertSame('unknown', $schedule->last_result);
        $this->assertStringContainsString('Review the order before resuming', (string) $schedule->last_error);

        // A second scheduler tick must leave the paused schedule untouched.
        $this->artisan('vtu:run-scheduled-purchases --limit=100')->assertExitCode(0);
        $this->assertSame('paused', $schedule->fresh()->status);
    }

}
