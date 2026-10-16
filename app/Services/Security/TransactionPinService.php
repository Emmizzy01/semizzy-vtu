<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TransactionPinService
{
    public function verify(User $user, string $pin): string
    {
        return DB::transaction(function () use ($user, $pin): string {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $locked->transaction_pin_hash) return 'not_configured';
            if ($locked->transaction_pin_locked_until && $locked->transaction_pin_locked_until->isFuture()) return 'locked';

            if (Hash::check($pin, $locked->transaction_pin_hash)) {
                $locked->forceFill(['transaction_pin_failed_attempts' => 0, 'transaction_pin_locked_until' => null])->save();
                $this->record($locked->id, 'pin_verified', 'success', []);
                return 'verified';
            }

            $attempts = (int) $locked->transaction_pin_failed_attempts + 1;
            $lockedUntil = $attempts >= 5 ? now()->addMinutes(15) : null;
            $locked->forceFill(['transaction_pin_failed_attempts' => $attempts, 'transaction_pin_locked_until' => $lockedUntil])->save();
            $this->record($locked->id, 'pin_verification', $lockedUntil ? 'locked' : 'failed', ['attempts' => $attempts]);
            return $lockedUntil ? 'locked' : 'invalid';
        }, attempts: 3);
    }

    public function record(?int $userId, string $event, string $outcome, array $context = []): void
    {
        DB::table('transaction_security_events')->insert([
            'user_id' => $userId,
            'event_type' => $event,
            'outcome' => $outcome,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
