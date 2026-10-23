<?php

namespace App\Services\Wallets;

use App\Models\BankPayout;
use App\Services\Payments\PaystackClient;
use Illuminate\Support\Facades\DB;
use LogicException;

class BankPayoutReconciliationService
{
    public function __construct(
        private readonly PaystackClient $paystack,
        private readonly WalletLedgerService $ledger,
    ) {}

    /** @param array<string, mixed> $payload
     *  @return array{outcome: string, duplicate: bool}
     */
    public function applyPaystackEvent(string $eventType, array $payload): array
    {
        if (! in_array($eventType, ['transfer.success', 'transfer.failed', 'transfer.reversed'], true)) {
            return ['outcome' => 'ignored', 'duplicate' => false];
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! is_string($data['reference'] ?? null) || $data['reference'] === '') {
            return ['outcome' => 'invalid_event', 'duplicate' => false];
        }

        $payout = BankPayout::query()->where('provider_reference', $data['reference'])->first();
        if (! $payout) {
            return ['outcome' => 'unmatched', 'duplicate' => false];
        }
        // Even terminal payouts pass provider verification so contradictory definitive
        // observations can be recorded without changing the settled financial outcome.

        $transferCode = $data['transfer_code'] ?? $payout->provider_transfer_code;
        if (! is_string($transferCode) || $transferCode === '') {
            return $this->markReconciliationRequired(
                $payout->id,
                'Transfer webhook did not contain a usable provider transfer code.',
            );
        }

        // Once a transfer code has been persisted, a webhook must refer to that
        // same transfer. Do not verify or settle a different transfer merely
        // because its payload repeats this payout's reference.
        $storedTransferCode = $payout->provider_transfer_code;
        if (is_string($storedTransferCode)
            && $storedTransferCode !== ''
            && ! hash_equals($storedTransferCode, $transferCode)) {
            return $this->markReconciliationRequired(
                $payout->id,
                'Transfer webhook code did not match the transfer already recorded for this payout.',
            );
        }

        try {
            $verified = $this->paystack->fetchBankTransfer($transferCode);
        } catch (\Throwable $exception) {
            // Any unexpected failure leaves the provider outcome unconfirmed.
            // Preserve the reservation and require reconciliation; never infer failure.
            report($exception);
            return $this->markReconciliationRequired(
                $payout->id,
                'Provider status could not be verified; funds remain reserved for reconciliation.',
            );
        }

        $verifiedAmount = $verified['amount'] ?? null;
        $verifiedAmount = is_int($verifiedAmount)
            ? $verifiedAmount
            : (is_string($verifiedAmount) && ctype_digit($verifiedAmount) ? (int) $verifiedAmount : null);
        $verifiedCurrency = is_string($verified['currency'] ?? null)
            ? strtoupper($verified['currency'])
            : null;

        // Reference/code alone do not prove this is the transfer reserved for this
        // payout. Require the provider's amount and currency to match before any
        // success transition or wallet compensation is permitted.
        if (($verified['reference'] ?? null) !== $payout->provider_reference
            || (string) ($verified['transfer_code'] ?? $transferCode) !== $transferCode
            || $verifiedAmount !== (int) $payout->amount_minor
            || $verifiedCurrency !== strtoupper((string) $payout->currency)) {
            return $this->markReconciliationRequired(
                $payout->id,
                'Verified provider transfer did not match the payout reference, amount, or currency.',
            );
        }

        $providerStatus = strtolower((string) ($verified['status'] ?? 'unknown'));
        if ($eventType === 'transfer.success' && $providerStatus !== 'success') {
            return $this->markReconciliationRequired(
                $payout->id,
                'Transfer-success webhook did not match verified provider status.',
            );
        }
        if (in_array($eventType, ['transfer.failed', 'transfer.reversed'], true) && ! in_array($providerStatus, ['failed', 'reversed'], true)) {
            return $this->markReconciliationRequired(
                $payout->id,
                'Transfer-failure webhook did not match a verified failed provider status.',
            );
        }

        return DB::transaction(function () use ($payout, $providerStatus, $transferCode, $eventType): array {
            $locked = BankPayout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'failed_refunded'
                || ($locked->status === 'succeeded' && $eventType !== 'transfer.reversed')) {
                $contradictsTerminal = ($locked->status === 'failed_refunded' && $providerStatus === 'success')
                    || ($locked->status === 'succeeded' && in_array($providerStatus, ['failed', 'reversed'], true));
                if ($contradictsTerminal) {
                    $metadata = $locked->metadata ?? [];
                    $conflict = [
                        'stored_status' => $locked->status,
                        'observed_provider_status' => $providerStatus,
                        'event_type' => $eventType,
                        'provider_transfer_code' => $transferCode,
                        'observed_at' => now()->toISOString(),
                    ];
                    $history = is_array($metadata['terminal_status_conflicts'] ?? null)
                        ? $metadata['terminal_status_conflicts']
                        : [];
                    $last = $history === [] ? null : $history[array_key_last($history)];
                    if (! is_array($last)
                        || ($last['stored_status'] ?? null) !== $locked->status
                        || ($last['observed_provider_status'] ?? null) !== $providerStatus
                        || ($last['event_type'] ?? null) !== $eventType) {
                        $history[] = $conflict;
                    }
                    $metadata['terminal_status_conflicts'] = array_slice($history, -10);
                    $metadata['terminal_status_conflict'] = $conflict;
                    $locked->forceFill(['metadata' => $metadata])->save();
                }

                return ['outcome' => $locked->status, 'duplicate' => true];
            }

            if ($providerStatus === 'success') {
                $locked->forceFill([
                    'status' => 'succeeded',
                    'provider_transfer_code' => $transferCode,
                    'failure_reason' => null,
                ])->save();

                return ['outcome' => 'succeeded', 'duplicate' => false];
            }

            $walletTransaction = $locked->walletTransaction;
            if (! $walletTransaction) {
                $locked->forceFill([
                    'status' => 'reconciliation_required',
                    'failure_reason' => 'Original wallet debit could not be found; refund requires manual review.',
                ])->save();

                return ['outcome' => 'reconciliation_required', 'duplicate' => false];
            }

            $this->ledger->post(
                walletId: (int) $walletTransaction->wallet_id,
                type: 'credit',
                amountMinor: (int) $locked->amount_minor,
                idempotencyKey: 'bank-payout:'.$locked->public_id.':failed-refund',
                reference: $locked->public_id,
                description: $providerStatus === 'reversed'
                    ? 'Refund for verified reversed bank payout'
                    : 'Refund for verified failed bank payout',
                actorUserId: null,
                metadata: ['payout_id' => $locked->public_id, 'provider_status' => $providerStatus],
                isFinancialRecovery: true,
            );

            $locked->forceFill([
                'status' => 'failed_refunded',
                'provider_transfer_code' => $transferCode,
                'failure_reason' => $providerStatus === 'reversed'
                    ? 'Provider confirmed reversal; wallet debit was reversed.'
                    : 'Provider confirmed failure; wallet debit was reversed.',
            ])->save();

            return ['outcome' => 'failed_refunded', 'duplicate' => false];
        }, attempts: 3);
    }
    /**
     * Mark uncertain provider outcomes under a row lock so a stale webhook cannot
     * overwrite a concurrently confirmed payout result.
     *
     * @return array{outcome: string, duplicate: bool}
     */
    private function markReconciliationRequired(int $payoutId, string $reason): array
    {
        return DB::transaction(function () use ($payoutId, $reason): array {
            $locked = BankPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();

            // A concurrent canonical event may have settled this payout while the
            // provider lookup was in flight. Do not downgrade a terminal result.
            if (in_array($locked->status, ['succeeded', 'failed_refunded'], true)) {
                return ['outcome' => $locked->status, 'duplicate' => true];
            }

            $locked->forceFill([
                'status' => 'reconciliation_required',
                'failure_reason' => $reason,
            ])->save();

            return ['outcome' => 'reconciliation_required', 'duplicate' => false];
        }, attempts: 3);
    }

}
