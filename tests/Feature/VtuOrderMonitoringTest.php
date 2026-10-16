<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\Vtu\VtuOrderMonitoringService;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class VtuOrderMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_ambiguous_orders_are_logged_and_webhook_alert_is_throttled_without_mutating_orders(): void
    {
        Cache::flush();
        Http::fake(['https://alerts.example.test/*' => Http::response(['ok' => true], 200)]);
        Log::spy();
        config([
            'services.vtu.stuck_order_minutes' => 60,
            'services.payments.alert_webhook_url' => 'https://alerts.example.test/semizzy',
        ]);

        $order = $this->createOrder('awaiting_reconciliation', now()->subHours(3), now());

        $monitor = app(VtuOrderMonitoringService::class);
        $first = $monitor->monitor();
        $second = $monitor->monitor();

        $this->assertSame(1, $first['stuck_total']);
        $this->assertSame(1, $first['awaiting_reconciliation']);
        $this->assertSame(1, $first['alerts_sent']);
        $this->assertSame(0, $second['alerts_sent']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['source'] === 'semizzy-vtu'
            && str_contains($request['text'], 'no automatic retry or refund'));
        Log::shouldHaveReceived('critical')->twice();

        $order->refresh();
        $this->assertSame('awaiting_reconciliation', $order->status);
        $this->assertSame(0, (int) $order->wallet_transaction_id);
        $this->assertDatabaseCount('vtu_orders', 1);
    }

    public function test_fresh_order_does_not_trigger_escalation_alert(): void
    {
        Cache::flush();
        Http::fake();
        config([
            'services.vtu.stuck_order_minutes' => 60,
            'services.payments.alert_webhook_url' => 'https://alerts.example.test/semizzy',
        ]);

        $this->createOrder('processing', now()->subMinutes(10), now());

        $stats = app(VtuOrderMonitoringService::class)->monitor();

        $this->assertSame(0, $stats['stuck_total']);
        Http::assertNothingSent();
    }


    public function test_old_order_without_dispatch_marker_is_not_misreported_as_a_dispatched_purchase(): void
    {
        Cache::flush();
        Http::fake();
        Log::spy();
        config([
            'services.vtu.stuck_order_minutes' => 60,
            'services.payments.alert_webhook_url' => 'https://alerts.example.test/semizzy',
        ]);

        $order = $this->createOrder('awaiting_reconciliation', now()->subHours(3), now());
        DB::table('vtu_orders')->where('id', $order->getKey())->update([
            'dispatch_started_at' => null,
        ]);

        $stats = app(VtuOrderMonitoringService::class)->monitor();

        $this->assertSame(0, $stats['stuck_total']);
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('critical');
        $this->assertSame('awaiting_reconciliation', $order->fresh()->status);
        $this->assertNull($order->fresh()->dispatch_started_at);
    }

    private function createOrder(string $status, $createdAt, $updatedAt): VtuOrder
    {
        $user = User::query()->create([
            'name' => 'VTU Monitor Customer',
            'email' => 'vtu-monitor-'.Str::uuid().'@example.test',
            'password' => 'test-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'mtn',
            'code' => 'monitor-product-'.Str::uuid(),
            'name' => 'Monitor Test Airtime',
            'price_minor' => 1000,
            'face_value_minor' => 1000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        $order = VtuOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'wallet_id' => $wallet->getKey(),
            'vtu_product_id' => $product->getKey(),
            'idempotency_key' => 'monitor-order-'.Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'service_type' => 'airtime',
            'network' => 'mtn',
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'amount_minor' => 1000,
            'currency' => 'NGN',
            'status' => $status,
            'provider' => 'vtpass',
            'failure_code' => 'provider_still_pending',
            'dispatch_started_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => now(),
        ]);

        // Set historical creation time at SQL level so Eloquent timestamp handling
        // cannot replace the old timestamp during model creation.
        DB::table('vtu_orders')->where('id', $order->getKey())->update([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        return $order->fresh();
    }
}
