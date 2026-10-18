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
            $deposit = ManualDeposit::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'wallet_id' => $wallet->getKey(),
                'amount_minor' => $amountMinor,
                'transfer_reference' => trim($transferReference),
                'evidence_path' => $evidencePath,
                'evidence_sha256' => $evidenceHash,
                'duplicate_evidence' => false,
                'status' => 'pending',
                'idempotency_key' => 'manual-deposit:'.Str::uuid(),
            ]);

            // Claim the receipt after creating the deposit inside this same
            // transaction. The unique fingerprint row serializes concurrent
            // submissions, and the winning row can point directly to its deposit.
            $claimed = DB::table('manual_deposit_evidence_fingerprints')->insertOrIgnore([
                'evidence_sha256' => $evidenceHash,
                'first_manual_deposit_id' => $deposit->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $duplicateEvidence = $claimed === 0;
            if ($duplicateEvidence) {
                $deposit->forceFill(['duplicate_evidence' => true])->save();
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
