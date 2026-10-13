<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Payments\PaymentIntentService;
use App\Services\Payments\PaymentRefundMonitoringService;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PaymentRefundMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_refunds_are_logged_and_alert_webhook_is_throttled(): void
    {
        Cache::flush();
        Http::fake(['https://alerts.example.test/*' => Http::response(['ok' => true], 200)]);
        Log::spy();
        config([
            'services.payments.refund_stale_minutes' => 15,
            'services.payments.alert_webhook_url' => 'https://alerts.example.test/semizzy',
        ]);

        $user = User::query()->create([
            'name' => 'Refund Monitor Customer',
            'email' => 'refund-monitor@example.test',
            'password' => 'test-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 12000, 'paystack');
        $intent->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $refund = PaymentRefund::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'payment_intent_id' => $intent->id,
            'requested_by_user_id' => $user->id,
            'idempotency_key' => 'monitor-stale-refund',
            'amount_minor' => 2000,
            'reason' => 'Monitoring test',
            'status' => 'provider_pending',
            'metadata' => ['currency' => 'NGN'],
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
            'status_changed_at' => now()->subHour(),
            'status_changed_at' => now()->subHour(),
        ]);

        $monitoring = app(PaymentRefundMonitoringService::class);
        $first = $monitoring->monitor();
        $second = $monitoring->monitor();

        $this->assertSame(1, $first['stuck_total']);
        $this->assertSame(1, $first['provider_pending']);
        $this->assertSame(1, $first['alerts_sent']);
        $this->assertSame(0, $second['alerts_sent']);
        Http::assertSentCount(1);
        Log::shouldHaveReceived('critical')->twice();
        $this->assertDatabaseHas('payment_refunds', ['id' => $refund->id, 'status' => 'provider_pending']);
    }

    public function test_fresh_refund_does_not_trigger_stale_alert(): void
    {
        Cache::flush();
        Http::fake();
        config([
            'services.payments.refund_stale_minutes' => 15,
            'services.payments.alert_webhook_url' => 'https://alerts.example.test/semizzy',
        ]);

        $user = User::query()->create([
            'name' => 'Fresh Refund Customer',
            'email' => 'refund-fresh@example.test',
            'password' => 'test-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 5000, 'paystack');
        $intent->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        PaymentRefund::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'payment_intent_id' => $intent->id,
            'requested_by_user_id' => $user->id,
            'idempotency_key' => 'monitor-fresh-refund',
            'amount_minor' => 1000,
            'reason' => 'Fresh request',
            'status' => 'processing',
            'metadata' => ['currency' => 'NGN'],
        ]);

        $stats = app(PaymentRefundMonitoringService::class)->monitor();
        $this->assertSame(0, $stats['stuck_total']);
        Http::assertNothingSent();
    }
}
