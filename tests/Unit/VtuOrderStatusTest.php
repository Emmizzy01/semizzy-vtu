<?php

namespace Tests\Unit;

use App\Enums\VtuOrderStatus;
use PHPUnit\Framework\TestCase;

class VtuOrderStatusTest extends TestCase
{
    public function test_only_explicitly_allowed_status_transitions_are_accepted(): void
    {
        $allowed = [
            [VtuOrderStatus::Pending, VtuOrderStatus::Processing],
            [VtuOrderStatus::Pending, VtuOrderStatus::Cancelled],
            [VtuOrderStatus::Processing, VtuOrderStatus::Successful],
            [VtuOrderStatus::Processing, VtuOrderStatus::Failed],
            [VtuOrderStatus::Processing, VtuOrderStatus::AwaitingReconciliation],
            [VtuOrderStatus::AwaitingReconciliation, VtuOrderStatus::Successful],
            [VtuOrderStatus::AwaitingReconciliation, VtuOrderStatus::Failed],
        ];

        foreach ($allowed as [$current, $next]) {
            $this->assertTrue(
                $current->canTransitionTo($next),
                "{$current->value} should be allowed to transition to {$next->value}."
            );
        }
    }

    public function test_terminal_statuses_cannot_transition_again(): void
    {
        foreach ([
            VtuOrderStatus::Successful,
            VtuOrderStatus::Failed,
            VtuOrderStatus::Cancelled,
        ] as $terminal) {
            foreach (VtuOrderStatus::cases() as $next) {
                $this->assertFalse(
                    $terminal->canTransitionTo($next),
                    "{$terminal->value} must not transition to {$next->value}."
                );
            }

            $this->assertTrue($terminal->isTerminal());
        }
    }

    public function test_processing_cannot_be_cancelled_after_dispatch(): void
    {
        $this->assertFalse(
            VtuOrderStatus::Processing->canTransitionTo(VtuOrderStatus::Cancelled)
        );
    }

    public function test_ambiguous_provider_result_requires_reconciliation(): void
    {
        $this->assertTrue(VtuOrderStatus::AwaitingReconciliation->requiresReconciliation());
        $this->assertFalse(VtuOrderStatus::AwaitingReconciliation->isTerminal());
        $this->assertFalse(VtuOrderStatus::Processing->requiresReconciliation());
    }
}
