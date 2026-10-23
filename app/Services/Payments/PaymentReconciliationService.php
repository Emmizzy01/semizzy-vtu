<?php

namespace App\Services\Payments;

use App\Exceptions\Wallet\IdempotencyConflict;
use App\Models\PaymentIntent;
use App\Models\PaymentReconciliationCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentReconciliationService
{
    public function __construct(
        private readonly PaystackClient $paystack,
        private readonly PaymentFundingService $funding,
    ) {
    }

    /**
     * Recheck old pending Paystack intents. Provider evidence is verified server-side;
     * mismatches and expired successes become durable review cases, never auto-credits.
     *
     * @return array{checked:int,credited:int,failed:int,exceptions:int,unavailable:int}
     */
    public function reconcile(int $limit = 100): array
    {
        $stats = ['checked' => 0, 'credited' => 0, 'failed' => 0, 'exceptions' => 0, 'unavailable' => 0];
        $graceMinutes = max(1, min(60, (int) config('services.payments.reconciliation_grace_minutes', 5)));

        PaymentIntent::query()
            ->where('provider', 'paystack')
            ->whereIn('status', ['pending', 'failed', 'review_required'])
            ->where('created_at', '<=', now()->subMinutes($graceMinutes))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->each(function (PaymentIntent $intent) use (&$stats): void {
                    $stats['checked']++;
                    // Rotate attempted intents so a repeatedly pending or unavailable provider
                    // reference cannot monopolize every scheduled batch.
                    PaymentIntent::query()->whereKey($intent->id)->update(['updated_at' => now()]);

                    try {
                        $intentMode = $intent->metadata['gateway_mode'] ?? null;
                        if (! in_array($intentMode, ['test', 'live'], true)) {
                            throw new \LogicException('Funding intent has no valid recorded gateway mode.');
                        }
                        $transaction = $this->paystack->verifyTransaction($intent->reference, $intentMode);
                    } catch (Throwable $exception) {
                        $stats['unavailable']++;
                        // Preserve a deduplicated operational record so a provider outage,
                        // mode/key mismatch, or verification error is visible for recovery.
                        // This is evidence of an unresolved check, never evidence of payment.
                        $this->recordCase($intent, 'verification_unavailable', [], [
                            'reason' => 'Paystack verification did not return trusted transaction data.',
                            'exception' => $exception::class,
                        ]);
                        Log::warning('Paystack reconciliation verification unavailable.', [
                            'reference' => $intent->reference,
                            'exception' => $exception::class,
                        ]);
                        return;
                    }

                    $providerStatus = is_string($transaction['status'] ?? null)
                        ? strtolower($transaction['status']) : 'unknown';
                    $transactionId = isset($transaction['id']) && is_numeric($transaction['id'])
                        ? (string) $transaction['id'] : null;
                    $amount = is_int($transaction['amount'] ?? null) ? $transaction['amount'] : null;
                    $currency = is_string($transaction['currency'] ?? null)
                        ? strtoupper($transaction['currency']) : null;
                    $referenceMatches = ($transaction['reference'] ?? null) === $intent->reference;

                    if ($providerStatus === 'success') {
                        if (! $referenceMatches || $amount !== $intent->amount_minor || $currency !== $intent->currency || $transactionId === null) {
                            $this->recordCase($intent, 'successful_payment_mismatch', $transaction, [
                                'reason' => 'Verified provider success does not match the payment intent.',
                            ]);
                            $stats['exceptions']++;
                            return;
                        }

                        // Only the wallet-cap review state has a guarded automatic recovery
                        // path. Failed intents are never reopened by provider success.
                        if ($intent->status === 'review_required') {
                            try {
                                $result = $this->funding->recoverVerifiedReview($intent, $transaction);
                                if (in_array($result['outcome'], ['credited', 'duplicate_payment'], true)) {
                                    // The funding service resolves matching cases in the same
                                    // transaction as settlement, avoiding a post-credit crash gap.
                                    $stats['credited']++;
                                } else {
                                    $this->recordCase($intent, $result['outcome'] === 'wallet_limit_review'
                                        ? 'wallet_limit_review' : 'late_success_review', $transaction, [
                                        'reason' => 'Canonical provider success was verified but guarded wallet recovery was not applied.',
                                        'funding_outcome' => $result['outcome'],
                                        'local_status' => $intent->status,
                                    ]);
                                    $stats['exceptions']++;
                                }
                            } catch (IdempotencyConflict $exception) {
                                $this->recordCase($intent, 'idempotency_conflict', $transaction, [
                                    'reason' => 'Verified payment recovery encountered an existing wallet transaction that conflicts with the canonical funding request or is not fully posted.',
                                    'exception' => $exception::class,
                                ]);
                                Log::critical('Paystack wallet recovery blocked by ledger idempotency conflict.', [
                                    'reference' => $intent->reference,
                                    'provider_transaction_id' => $transactionId,
                                    'exception' => $exception::class,
                                ]);
                                $stats['exceptions']++;
                            } catch (Throwable $exception) {
                                $this->recordCase($intent, 'settlement_error', $transaction, [
                                    'reason' => 'Canonical provider success could not complete guarded wallet-limit recovery.',
                                    'exception' => $exception::class,
                                ]);
                                Log::error('Paystack wallet-limit recovery failed.', [
                                    'reference' => $intent->reference,
                                    'exception' => $exception::class,
                                ]);
                                $stats['exceptions']++;
                            }

                            return;
                        }

                        // A failed intent remains review-only even when Paystack later reports
                        // success. It requires an audited operational decision, not reopening.
                        if ($intent->status !== 'pending') {
                            $this->recordCase($intent, 'late_success_review', $transaction, [
                                'reason' => 'Provider reports success while the local intent is no longer pending; automatic settlement was not attempted.',
                                'local_status' => $intent->status,
                            ]);
                            $stats['exceptions']++;
                            return;
                        }

                        try {
                            $result = $this->funding->processVerifiedEvent('paystack', [
                                'id' => 'paystack-reconcile-success-'.$transactionId,
                                'type' => 'payment.succeeded',
                                'data' => [
                                    'reference' => $intent->reference,
                                    'transaction_id' => $transactionId,
                                    'status' => 'succeeded',
                                    'amount_minor' => $amount,
                                    'currency' => $currency,
                                ],
                            ]);
                            if (in_array($result['outcome'], ['credited', 'duplicate_payment'], true)) {
                                $stats['credited']++;
                            } else {
                                $this->recordCase($intent, 'settlement_not_applied', $transaction, [
                                    'reason' => 'Verified success was not automatically settled.',
                                    'funding_outcome' => $result['outcome'],
                                ]);
                                $stats['exceptions']++;
                            }
                        } catch (IdempotencyConflict $exception) {
                            $this->recordCase($intent, 'idempotency_conflict', $transaction, [
                                'reason' => 'Verified payment settlement encountered an existing wallet transaction that conflicts with the canonical funding request or is not fully posted.',
                                'exception' => $exception::class,
                            ]);
                            Log::critical('Paystack settlement blocked by ledger idempotency conflict.', [
                                'reference' => $intent->reference,
                                'provider_transaction_id' => $transactionId,
                                'exception' => $exception::class,
                            ]);
                            $stats['exceptions']++;
                        } catch (Throwable $exception) {
                            $this->recordCase($intent, 'settlement_error', $transaction, [
                                'reason' => 'Verified success could not be automatically settled.',
                                'exception' => $exception::class,
                            ]);
                            Log::error('Paystack reconciliation settlement failed.', [
                                'reference' => $intent->reference,
                                'exception' => $exception::class,
                            ]);
                            $stats['exceptions']++;
                        }

                        return;
                    }

                    if (in_array($providerStatus, ['failed', 'abandoned'], true)) {
                        // A terminal failure is still provider evidence and must identify
                        // this exact intent. Do not fail a local payment from a malformed
                        // or mismatched verification response.
                        if (! $referenceMatches
                            || $amount !== $intent->amount_minor
                            || $currency !== $intent->currency
                            || $transactionId === null) {
                            $this->recordCase($intent, 'failed_payment_mismatch', $transaction, [
                                'reason' => 'Verified provider failure does not match the payment intent reference, amount, currency, or transaction ID.',
                            ]);
                            $stats['exceptions']++;
                            return;
                        }

                        $settledByThisWorker = false;

                        if ($intent->status === 'pending') {
                            $settledByThisWorker = PaymentIntent::query()
                                ->whereKey($intent->id)
                                ->where('status', 'pending')
                                ->update([
                                    'status' => 'failed',
                                    'updated_at' => now(),
                                ]) === 1;
                        }

                        // Provider verification can race with a successful webhook or
                        // another reconciliation worker. Never label the intent failed
                        // merely because our earlier snapshot was pending.
                        $currentStatus = PaymentIntent::query()->whereKey($intent->id)->value('status');
                        if ($currentStatus === 'paid') {
                            $this->recordCase($intent, 'provider_terminal_status_conflict', $transaction, [
                                'reason' => 'Provider verification reported failure after the local intent was already paid; manual reconciliation is required.',
                                'local_status' => $currentStatus,
                            ]);
                            $stats['exceptions']++;
                            return;
                        }

                        if ($currentStatus !== 'failed' && ! $settledByThisWorker) {
                            $this->recordCase($intent, 'provider_terminal_status_conflict', $transaction, [
                                'reason' => 'Provider verification reported failure while the local intent was in a competing state.',
                                'local_status' => $currentStatus,
                            ]);
                            $stats['exceptions']++;
                            return;
                        }

                        $this->recordCase($intent, 'provider_failed', $transaction, [
                            'reason' => 'Provider verification reports a failed or abandoned transaction.',
                        ]);
                        $stats['failed']++;
                        return;
                    }

                    $this->recordCase($intent, 'provider_pending', $transaction, [
                        'reason' => 'Provider verification has not confirmed a final successful payment.',
                    ]);
                    $stats['exceptions']++;
            });

        return $stats;
    }

    /** @param array<string, mixed> $transaction @param array<string, mixed> $details */
    private function recordCase(PaymentIntent $intent, string $type, array $transaction, array $details): void
    {
        $transactionId = isset($transaction['id']) && is_numeric($transaction['id'])
            ? (string) $transaction['id'] : null;
        $observedAt = now();

        DB::transaction(function () use ($intent, $type, $transactionId, $observedAt, $transaction, $details): void {
            // Match settlement's intent-first lock order. If settlement wins the
            // race, classify stale failure/conflict evidence against the paid row.
            $currentIntent = PaymentIntent::query()
                ->whereKey($intent->id)
                ->lockForUpdate()
                ->first();

            $caseType = $type;
            $caseDetails = $details;
            $staleAfterSettlementTypes = [
                'provider_failed',
                'provider_terminal_status_conflict',
                'failed_payment_mismatch',
                'successful_payment_mismatch',
                'idempotency_conflict',
                'settlement_error',
                'settlement_not_applied',
                'wallet_limit_review',
                'late_success_review',
            ];

            if ($currentIntent !== null
                && $currentIntent->status === 'paid'
                && in_array($type, $staleAfterSettlementTypes, true)) {
                $caseType = 'post_settlement_provider_conflict';
                $caseDetails['observed_case_type'] = $type;
                $caseDetails['local_status_at_recording'] = 'paid';
                $caseDetails['reason'] = 'Provider/reconciliation evidence conflicted with an intent that was already settled; the original provider evidence is retained for manual review.';
            }

            $key = hash('sha256', implode('|', [
                'paystack',
                $intent->reference,
                $caseType,
                $transactionId ?? 'no-transaction-id',
            ]));

            $observation = [
                'observed_at' => $observedAt->toISOString(),
                'provider_status' => is_string($transaction['status'] ?? null) ? $transaction['status'] : null,
                'provider_transaction_id' => $transactionId,
                'observed_amount_minor' => is_int($transaction['amount'] ?? null) ? $transaction['amount'] : null,
                'observed_currency' => is_string($transaction['currency'] ?? null) ? strtoupper($transaction['currency']) : null,
                'details' => $caseDetails,
            ];

            $attributes = [
                'provider' => 'paystack',
                'reference' => $intent->reference,
                'payment_intent_id' => $intent->id,
                'case_type' => $caseType,
                'provider_status' => $observation['provider_status'],
                'provider_transaction_id' => $transactionId,
                'expected_amount_minor' => $intent->amount_minor,
                'observed_amount_minor' => $observation['observed_amount_minor'],
                'expected_currency' => $intent->currency,
                'observed_currency' => $observation['observed_currency'],
                'details' => [
                    'reason' => $caseDetails['reason'] ?? 'Payment reconciliation requires review.',
                    'observations' => [$observation],
                ],
                'last_checked_at' => $observedAt,
            ];

            PaymentReconciliationCase::query()->firstOrCreate(
                ['case_key' => $key],
                $attributes + ['status' => 'open'],
            );

            $case = PaymentReconciliationCase::query()
                ->where('case_key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            $existingDetails = is_array($case->details) ? $case->details : [];
            $observations = is_array($existingDetails['observations'] ?? null)
                ? $existingDetails['observations'] : [];

            $observationHash = hash('sha256', json_encode($observation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $lastObservation = $observations === [] ? null : $observations[array_key_last($observations)];
            $lastObservationHash = is_array($lastObservation)
                ? hash('sha256', json_encode($lastObservation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                : null;

            if ($observationHash !== $lastObservationHash) {
                $observations[] = $observation;
            }

            if (count($observations) > 25) {
                $observations = array_merge([$observations[0]], array_slice($observations, -24));
            }

            $existingDetails['observations'] = $observations;
            $existingDetails['last_observed_at'] = $observedAt->toISOString();
            if (! isset($existingDetails['reason']) && isset($caseDetails['reason'])) {
                $existingDetails['reason'] = $caseDetails['reason'];
            }

            $case->forceFill([
                'provider' => $case->provider ?: 'paystack',
                'reference' => $case->reference ?: $intent->reference,
                'payment_intent_id' => $case->payment_intent_id ?: $intent->id,
                'expected_amount_minor' => $case->expected_amount_minor ?? $intent->amount_minor,
                'expected_currency' => $case->expected_currency ?: $intent->currency,
                'details' => $existingDetails,
                'last_checked_at' => $observedAt,
            ])->save();
        }, attempts: 3);
    }
}
