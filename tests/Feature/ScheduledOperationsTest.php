<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduledOperationsTest extends TestCase
{
    public function test_financial_and_vtu_reconciliation_and_monitoring_jobs_remain_scheduled(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertScheduledCommand($events, 'payments:reconcile-paystack --limit=100', '*/5 * * * *');
        $this->assertScheduledCommand($events, 'payments:reconcile-refunds --limit=100', '*/5 * * * *');
        $this->assertScheduledCommand($events, 'vtu:reconcile-orders --limit=100', '*/5 * * * *');
        $this->assertScheduledCommand($events, 'vtu:monitor-orders', '*/15 * * * *');
    }

    private function assertScheduledCommand(array $events, string $commandFragment, string $expression): void
    {
        $event = collect($events)->first(
            fn ($scheduledEvent): bool => str_contains((string) $scheduledEvent->command, $commandFragment),
        );

        $this->assertNotNull($event, "Expected scheduled command containing [{$commandFragment}].");
        $this->assertSame($expression, $event->expression);
    }
}
