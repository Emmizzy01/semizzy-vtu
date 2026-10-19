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
        if (in_array($payout->status, ['succeeded', 'failed_refunded'], true)) {
            return ['outcome' => $payout->status, 'duplicate' => true];
        }

        $transferCode = $data['transfer_code'] ?? $payout->provider_transfer_code;
        if (! is_string($transferCode) || $transferCode === '') {
            $payout->forceFill([
                'status' => 'reconciliation_required',
                'failure_reason' => 'Transfer webhook did not contain a usable provider transfer code.',
            ])->save();

            return ['outcome' => 'reconciliation_required', 'duplicate' => false];
        }

        try {
            $verified = $this->paystack->fetchBankTransfer($transferCode);
        } catch (LogicException $exception) {
            report($exception);
            $payout->forceFill([
                'status' => 'reconciliation_required',
                'failure_reason' => 'Provider status could not be verified; funds remain reserved for reconciliation.',
            ])->save();

            return ['outcome' => 'reconciliation_required', 'duplicate' => false];
        }

        if (($verified['reference'] ?? null) !== $payout->provider_reference
            || (string) ($verified['transfer_code'] ?? $transferCode) !== $transferCode) {
            $payout->forceFill([
                'status' => 'reconciliation_required',
                'failure_reason' => 'Verified provider transfer did not match the payout reference.',
            ])->save();

            return ['outcome' => 'reconciliation_required', 'duplicate' => false];
        }

        $providerStatus = strtolower((string) ($verified['status'] ?? 'unknown'));
        if ($eventType === 'transfer.success' && $providerStatus !== 'success') {
            $payout->forceFill(['status' => 'reconciliation_required', 'failure_reason' => 'Transfer-success webhook did not match verified provider status.'])->save();
            return ['outcome' => 'reconciliation_required', 'duplicate' => false];
        }
        if (in_array($eventType, ['transfer.failed', 'transfer.reversed'], true) && ! in_array($providerStatus, ['failed', 'reversed'], true)) {
            $payout->forceFill(['status' => 'reconciliation_required', 'failure_reason' => 'Transfer-failure webhook did not match a verified failed provider status.'])->save();
            return ['outcome' => 'reconciliation_required', 'duplicate' => false];
        }

        return DB::transaction(function () use ($payout, $providerStatus, $transferCode): array {
            $locked = BankPayout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['succeeded', 'failed_refunded'], true)) {
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
                description: 'Refund for verified failed bank payout',
                actorUserId: null,
                metadata: ['payout_id' => $locked->public_id, 'provider_status' => $providerStatus],
                isFinancialRecovery: true,
            );

            $locked->forceFill([
                'status' => 'failed_refunded',
                'provider_transfer_code' => $transferCode,
                'failure_reason' => 'Provider confirmed failure; wallet debit was reversed.',
            ])->save();

            return ['outcome' => 'failed_refunded', 'duplicate' => false];
        }, attempts: 3);
    }
}
