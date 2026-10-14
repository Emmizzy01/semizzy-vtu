<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduledOperationsTest extends TestCase
{
    public function test_financial_and_vtu_reconciliation_and_monitoring_jobs_remain_scheduled(): void
    {
        $events = app(Schedule::class)->events();

        $scheduled = collect($events)->mapWithKeys(function ($event): array {
            return [$event->command => $event->expression];
        });

        $this->assertArrayHasKey('php artisan payments:reconcile-paystack --limit=100', $scheduled);
        $this->assertSame('*/5 * * * *', $scheduled['php artisan payments:reconcile-paystack --limit=100']);

        $this->assertArrayHasKey('php artisan payments:reconcile-refunds --limit=100', $scheduled);
        $this->assertSame('*/5 * * * *', $scheduled['php artisan payments:reconcile-refunds --limit=100']);

        $this->assertArrayHasKey('php artisan vtu:reconcile-orders --limit=100', $scheduled);
        $this->assertSame('*/5 * * * *', $scheduled['php artisan vtu:reconcile-orders --limit=100']);

        $this->assertArrayHasKey('php artisan vtu:monitor-orders', $scheduled);
        $this->assertSame('*/15 * * * *', $scheduled['php artisan vtu:monitor-orders']);
    }
}
