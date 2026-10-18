<?php

namespace App\Services\Payments;

use App\Models\ManualDeposit;
use App\Models\ManualDepositAudit;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ManualDepositSubmissionService
{
    public function submit(
        User $user,
        Wallet $wallet,
        int $amountMinor,
        string $transferReference,
        string $evidencePath,
        string $evidenceHash,
    ): ManualDeposit {
        if ((int) $wallet->user_id !== (int) $user->getKey() || $wallet->currency !== 'NGN') {
            throw new InvalidArgumentException('Manual deposit wallet does not belong to the submitting customer.');
        }
        if ($amountMinor < 10000 || $amountMinor > 500000000) {
            throw new InvalidArgumentException('Manual deposit amount is outside the allowed range.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $evidenceHash)) {
            throw new InvalidArgumentException('Manual deposit evidence fingerprint is invalid.');
        }

        return DB::transaction(function () use ($user, $wallet, $amountMinor, $transferReference, $evidencePath, $evidenceHash): ManualDeposit {
            // The unique primary key serializes simultaneous submissions of the
            // same receipt, even when neither request can see an existing deposit.
            $claimed = DB::table('manual_deposit_evidence_fingerprints')->insertOrIgnore([
                'evidence_sha256' => $evidenceHash,
                'first_manual_deposit_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $duplicateEvidence = $claimed === 0;

            $deposit = ManualDeposit::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'wallet_id' => $wallet->getKey(),
                'amount_minor' => $amountMinor,
                'transfer_reference' => trim($transferReference),
                'evidence_path' => $evidencePath,
                'evidence_sha256' => $evidenceHash,
                'duplicate_evidence' => $duplicateEvidence,
                'status' => 'pending',
                'idempotency_key' => 'manual-deposit:'.Str::uuid(),
            ]);

            if (! $duplicateEvidence) {
                DB::table('manual_deposit_evidence_fingerprints')
                    ->where('evidence_sha256', $evidenceHash)
                    ->update(['first_manual_deposit_id' => $deposit->getKey(), 'updated_at' => now()]);
            }

            ManualDepositAudit::query()->create([
                'manual_deposit_id' => $deposit->getKey(),
                'actor_user_id' => $user->getKey(),
                'event_type' => 'submitted',
                'outcome' => 'pending',
                'context' => ['amount_minor' => $amountMinor, 'currency' => 'NGN'],
                'created_at' => now(),
            ]);

            if ($duplicateEvidence) {
                ManualDepositAudit::query()->create([
                    'manual_deposit_id' => $deposit->getKey(),
                    'actor_user_id' => $user->getKey(),
                    'event_type' => 'duplicate_evidence_detected',
                    'outcome' => 'flagged',
                    'created_at' => now(),
                ]);
            }

            return $deposit;
        }, attempts: 3);
    }
}
