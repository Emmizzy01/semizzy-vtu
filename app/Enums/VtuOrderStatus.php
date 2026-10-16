<?php

namespace App\Enums;

enum VtuOrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Successful = 'successful';
    case Failed = 'failed';
    case AwaitingReconciliation = 'awaiting_reconciliation';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Processing, self::Cancelled],
            self::Processing => [
                self::Successful,
                self::Failed,
                self::AwaitingReconciliation,
            ],
            self::AwaitingReconciliation => [self::Successful, self::Failed],
            self::Successful, self::Failed, self::Cancelled => [],
        }, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Successful,
            self::Failed,
            self::Cancelled,
        ], true);
    }

    public function requiresReconciliation(): bool
    {
        return $this === self::AwaitingReconciliation;
    }
}
