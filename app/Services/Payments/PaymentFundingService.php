<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\PaymentReconciliationCase;
use App\Models\PaymentWebhookEvent;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Services\Wallets\WalletLedgerService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PaymentFundingService
{
    public function __construct(private readonly WalletLedgerService $ledger)
    {
    }

    /**
     * Expected normalized event: id, type, data.reference, data.transaction_id,
     * data.status, data.amount_minor, data.currency. Call only after signature verification.
     *
     * @param array<string, mixed> $payload
     * @return array{outcome:string, duplicate:bool}
     */
    public function processVerifiedEvent(string $provider, array $payload): array
    {
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;

        if (! is_string($eventId) || $eventId === '' || strlen($eventId) > 190
            || ! is_string($eventType) || $eventType === ''
            || ! is_array($data)) {
            throw new UnprocessableEntityHttpException('Webhook event schema is invalid.');
        }

        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        try {
            return DB::transaction(function () use ($provider, $eventId, $eventType, $data, $payloadHash, $payload): array {
                $event = PaymentWebhookEvent::query()->create([
                    'provider' => $provider,
                    'event_id' => $eventId,
                    'payload_hash' => $payloadHash,
                    'event_type' => $eventType,
                    'outcome' => 'received',
                    'safe_payload' => [
                        'id' => $eventId,
                        'type' => $eventType,
                        'reference' => is_string($data['reference'] ?? null) ? $data['reference'] : null,
                        'transaction_id' => is_string($data['transaction_id'] ?? null) ? $data['transaction_id'] : null,
                        'status' => is_string($data['status'] ?? null) ? $data['status'] : null,
                    ],
                ]);

                if ($eventType !== 'payment.succeeded' || ($data['status'] ?? null) !== 'succeeded') {
                    $event->forceFill(['outcome' => 'ignored', 'processed_at' => now()])->save();

                    return ['outcome' => 'ignored', 'duplicate' => false];
                }

                $reference = $data['reference'] ?? null;
                $providerTransactionId = $data['transaction_id'] ?? null;
                $amount = $data['amount_minor'] ?? null;
                $currency = $data['currency'] ?? null;

                if (! is_string($reference) || $reference === ''
                    || ! is_string($providerTransactionId) || $providerTransactionId === ''
                    || ! is_int($amount) || $amount < 1
                    || ! is_string($currency)) {
                    throw new UnprocessableEntityHttpException('Successful payment event is missing required fields.');
                }

                /** @var PaymentIntent|null $intent */
                $intent = PaymentIntent::query()->where('reference', $reference)->lockForUpdate()->first();
                if ($intent === null || $intent->provider !== $provider) {
                    throw new UnprocessableEntityHttpException('Payment reference was not found for this provider.');
                }


                // A financial mismatch on an already-settled intent is durable conflict evidence,
                // not an exception that rolls back the webhook event and its audit trail.
                if ($intent->amount_minor !== $amount || $intent->currency !== strtoupper($currency)) {
                    if (in_array($intent->status, ['paid', 'test_paid'], true)) {
                        $caseTransactionId = $providerTransactionId;
                        $caseKey = hash('sha256', implode('|', [
                            $provider,
                            $intent->reference,
                            'successful_payment_mismatch',
                            $caseTransactionId,
                        ]));
                        PaymentReconciliationCase::query()->firstOrCreate(
                            ['case_key' => $caseKey],
                            [
                                'provider' => $provider,
                                'reference' => $intent->reference,
                                'payment_intent_id' => $intent->id,
                                'case_type' => 'successful_payment_mismatch',
                                'provider_status' => 'success',
                                'provider_transaction_id' => $caseTransactionId,
                                'expected_amount_minor' => $intent->amount_minor,
                                'observed_amount_minor' => $amount,
                                'expected_currency' => $intent->currency,
                                'observed_currency' => strtoupper($currency),
                                'details' => [
                                    'reason' => 'A successful provider event conflicts with the amount or currency of an already-settled intent. No additional wallet credit was attempted.',
                                    'settled_provider_transaction_id' => $intent->provider_transaction_id,
                                    'observed_provider_transaction_id' => $caseTransactionId,
                                    'local_status' => $intent->status,
                                    'requires_manual_review' => true,
                                ],
                                'status' => 'open',
                                'last_checked_at' => now(),
                            ],
                        );
                        $event->forceFill([
                            'outcome' => 'successful_payment_mismatch',
                            'payment_intent_id' => $intent->id,
                            'processed_at' => now(),
                        ])->save();

                        return ['outcome' => 'successful_payment_mismatch', 'duplicate' => false];
                    }

                    throw new ConflictHttpException('Payment amount or currency does not match the intent.');
                }

                // Different event IDs can describe the same provider transaction. The intent row lock
                // serializes them; only the original settlement may credit the wallet.
                if (in_array($intent->status, ['paid', 'test_paid'], true)) {
                    if ($intent->provider_transaction_id !== $providerTransactionId) {
                        // Persist contradictory provider evidence in the same transaction as
                        // the webhook observation. Throwing here would roll back both records,
                        // losing the conflict precisely when another worker already settled.
                        $caseKey = hash('sha256', implode('|', [
                            $provider,
                            $intent->reference,
                            'successful_payment_mismatch',
                            $providerTransactionId,
                        ]));
                        PaymentReconciliationCase::query()->firstOrCreate(
                            ['case_key' => $caseKey],
                            [
                                'provider' => $provider,
                                'reference' => $intent->reference,
                                'payment_intent_id' => $intent->id,
                                'case_type' => 'successful_payment_mismatch',
                                'provider_status' => 'success',
                                'provider_transaction_id' => $providerTransactionId,
                                'expected_amount_minor' => $intent->amount_minor,
                                'observed_amount_minor' => $amount,
                                'expected_currency' => $intent->currency,
                                'observed_currency' => strtoupper($currency),
                                'details' => [
                                    'reason' => 'A successful provider event reported a different transaction ID after this intent was already settled. No additional wallet credit was attempted.',
                                    'settled_provider_transaction_id' => $intent->provider_transaction_id,
                                    'observed_provider_transaction_id' => $providerTransactionId,
                                    'local_status' => $intent->status,
                                    'requires_manual_review' => true,
                                ],
                                'status' => 'open',
                                'last_checked_at' => now(),
                            ],
                        );
                        $event->forceFill([
                            'outcome' => 'successful_payment_mismatch',
                            'payment_intent_id' => $intent->id,
                            'processed_at' => now(),
                        ])->save();

                        return ['outcome' => 'successful_payment_mismatch', 'duplicate' => false];
                    }

                    $duplicateOutcome = $intent->status === 'test_paid' ? 'test_succeeded' : 'duplicate_payment';
                    $event->forceFill([
                        'outcome' => $duplicateOutcome,
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => $duplicateOutcome, 'duplicate' => true];
                }

                // A late signed Paystack success must not disappear when an earlier
                // reconciliation marked the intent failed or wallet-limit review was needed.
                // Persist durable review evidence atomically with the webhook event; never
                // reopen the intent or post a second credit from this late observation.
                if ($provider === 'paystack' && in_array($intent->status, ['failed', 'review_required'], true)) {
                    $caseKey = hash('sha256', implode('|', [
                        'paystack',
                        $intent->reference,
                        'late_success_review',
                        $providerTransactionId,
                    ]));
                    PaymentReconciliationCase::query()->firstOrCreate(
                        ['case_key' => $caseKey],
                        [
                            'provider' => 'paystack',
                            'reference' => $intent->reference,
                            'payment_intent_id' => $intent->id,
                            'case_type' => 'late_success_review',
                            'provider_status' => 'success',
                            'provider_transaction_id' => $providerTransactionId,
                            'expected_amount_minor' => $intent->amount_minor,
                            'observed_amount_minor' => $amount,
                            'expected_currency' => $intent->currency,
                            'observed_currency' => strtoupper($currency),
                            'details' => [
                                'reason' => 'Signed provider success arrived after the local intent entered a non-pending state; automatic settlement was withheld.',
                                'local_status' => $intent->status,
                                'requires_canonical_reconciliation' => true,
                            ],
                            'status' => 'open',
                            'last_checked_at' => now(),
                        ],
                    );
                    $event->forceFill([
                        'outcome' => 'late_success_review',
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => 'late_success_review', 'duplicate' => false];
                }

                // A provider-verified Paystack success must still be reconciled after checkout expiry.
                if ($intent->status !== 'pending' || ($provider !== 'paystack' && $intent->expires_at && $intent->expires_at->isPast())) {
                    $event->forceFill([
                        'outcome' => 'rejected',
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => 'rejected', 'duplicate' => false];
                }
                // Live settlements remain fail-closed until the payment release gate is explicitly opened.
                // Throwing rolls back the webhook event too, allowing safe reconciliation/retry later.
                $intentGatewayMode = $intent->metadata['gateway_mode'] ?? null;
                if ($provider === 'paystack' && $intentGatewayMode !== 'test'
                    && ! config('services.semizzy_payments.live_funding_enabled', false)) {
                    // Legacy intents with no recorded mode are fail-closed too; missing metadata
                    // must never bypass the live-settlement release gate.
                    throw new LogicException('Live wallet funding settlement is disabled until payment safety checks pass.');
                }

                // Test-mode successes are recorded for end-to-end verification, but must never
                // become real production wallet funds. Only explicitly selected Live intents post to the ledger.
                if ($provider === 'paystack' && ($intent->metadata['gateway_mode'] ?? null) === 'test') {
                    $intent->forceFill([
                        'status' => 'test_paid',
                        'provider_transaction_id' => $providerTransactionId,
                        'paid_at' => now(),
                    ])->save();

                    $event->forceFill([
                        'outcome' => 'test_succeeded',
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => 'test_succeeded', 'duplicate' => false];
                }

                try {
                    $this->ledger->post(
                        walletId: $intent->wallet_id,
                        type: 'credit',
                        amountMinor: $intent->amount_minor,
                        idempotencyKey: 'funding:'.hash('sha256', $provider.':'.$providerTransactionId),
                        reference: $intent->reference,
                        description: 'Verified wallet funding',
                        metadata: [
                            'payment_intent_id' => $intent->public_id,
                            'provider' => $provider,
                            'provider_transaction_id' => $providerTransactionId,
                        ],
                        correlationId: (string) Str::uuid(),
                    );
                } catch (IdempotencyConflict $exception) {
                    // Keep the webhook event, payment-intent lock, and reconciliation
                    // evidence in this transaction. Throwing would roll back the case
                    // along with the event, forcing the conflict to be rediscovered later.
                    $caseKey = hash('sha256', implode('|', [
                        $provider,
                        $intent->reference,
                        'idempotency_conflict',
                        $providerTransactionId,
                    ]));
                    PaymentReconciliationCase::query()->firstOrCreate(
                        ['case_key' => $caseKey],
                        [
                            'provider' => $provider,
                            'reference' => $intent->reference,
                            'payment_intent_id' => $intent->id,
                            'case_type' => 'idempotency_conflict',
                            'provider_status' => 'success',
                            'provider_transaction_id' => $providerTransactionId,
                            'expected_amount_minor' => $intent->amount_minor,
                            'observed_amount_minor' => $amount,
                            'expected_currency' => $intent->currency,
                            'observed_currency' => strtoupper($currency),
                            'details' => [
                                'reason' => 'Verified funding could not be posted because the existing wallet idempotency record conflicts or is incomplete. No alternate credit key was attempted.',
                                'exception' => $exception::class,
                                'requires_manual_review' => true,
                            ],
                            'status' => 'open',
                            'last_checked_at' => now(),
                        ],
                    );
                    $event->forceFill([
                        'outcome' => 'idempotency_conflict',
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => 'idempotency_conflict', 'duplicate' => false];
                } catch (DomainException $exception) {
                    if (! str_contains($exception->getMessage(), 'account level would be exceeded')) {
                        throw $exception;
                    }

                    // The gateway payment is verified but could not be credited.
                    // Persist it for reconciliation instead of losing it or calling it settled.
                    $intent->forceFill([
                        'status' => 'review_required',
                        'provider_transaction_id' => $providerTransactionId,
                        'paid_at' => now(),
                    ])->save();
                    $event->forceFill([
                        'outcome' => 'wallet_limit_review',
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => 'wallet_limit_review', 'duplicate' => false];
                }

                $intent->forceFill([
                    'status' => 'paid',
                    'provider_transaction_id' => $providerTransactionId,
                    'paid_at' => now(),
                ])->save();
                $event->forceFill([
                    'outcome' => 'credited',
                    'payment_intent_id' => $intent->id,
                    'processed_at' => now(),
                ])->save();

                // Resolve only retry breadcrumbs that describe this exact provider
                // transaction. Keep this in the same transaction as ledger posting,
                // intent settlement, and event outcome persistence.
                $this->resolveWebhookProcessingRetryCases($intent, $providerTransactionId);

                return ['outcome' => 'credited', 'duplicate' => false];
            }, attempts: 3);
        } catch (QueryException $exception) {
            // A database failure rolls back the settlement transaction, including any
            // ledger writes made on this connection. If the database recovers enough to
            // accept a separate case write, retain a durable retry breadcrumb; still
            // rethrow so the provider receives a failure and retries delivery.
            if (! $this->isUniqueConstraintViolation($exception)) {
                $reference = is_string($data['reference'] ?? null) ? $data['reference'] : null;
                $transactionId = is_string($data['transaction_id'] ?? null) ? $data['transaction_id'] : null;

                if ($eventType === 'payment.succeeded'
                    && ($data['status'] ?? null) === 'succeeded'
                    && $reference !== null && $reference !== '') {
                    try {
                        $intent = PaymentIntent::query()
                            ->where('provider', $provider)
                            ->where('reference', $reference)
                            ->first();

                        if ($intent !== null) {
                            $caseKey = hash('sha256', implode('|', [
                                $provider,
                                $reference,
                                'webhook_processing_retry',
                                $eventId,
                                $payloadHash,
                            ]));

                            DB::transaction(function () use (
                                $provider, $reference, $transactionId, $eventId, $payloadHash, $exception, $intent, $caseKey, $data
                            ): void {
                                PaymentReconciliationCase::query()->firstOrCreate(
                                    ['case_key' => $caseKey],
                                    [
                                        'provider' => $provider,
                                        'reference' => $reference,
                                        'payment_intent_id' => $intent->id,
                                        'case_type' => 'webhook_processing_retry',
                                        'provider_status' => 'success',
                                        'provider_transaction_id' => $transactionId,
                                        'expected_amount_minor' => $intent->amount_minor,
                                        'observed_amount_minor' => is_int($data['amount_minor'] ?? null) ? $data['amount_minor'] : null,
                                        'expected_currency' => $intent->currency,
                                        'observed_currency' => is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : null,
                                        'details' => [
                                            'reason' => 'A verified success webhook could not finish its database transaction. Settlement was rolled back and must be retried/reconciled; no second credit key was generated.',
                                            'event_id' => $eventId,
                                            'payload_hash' => $payloadHash,
                                            'database_exception' => $exception::class,
                                            'retry_required' => true,
                                        ],
                                        'status' => 'open',
                                        'last_checked_at' => now(),
                                    ],
                                );
                            }, attempts: 3);
                        }
                    } catch (\Throwable $recoveryException) {
                        // Do not mask the original database failure. If the database
                        // cannot persist the breadcrumb either, provider retry and the
                        // normal pending-intent reconciliation remain the recovery paths.
                    }
                }

                throw $exception;
            }

            $existing = PaymentWebhookEvent::query()
                ->where('provider', $provider)
                ->where('event_id', $eventId)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            if (! hash_equals($existing->payload_hash, $payloadHash)) {
                // The original event is immutable. Persist the conflicting replay separately
                // so its evidence survives the duplicate-key rollback of the attempted insert.
                $incomingReference = is_string($data['reference'] ?? null) ? $data['reference'] : null;
                $incomingTransactionId = is_string($data['transaction_id'] ?? null)
                    ? $data['transaction_id'] : null;
                $intent = $existing->payment_intent_id !== null
                    ? PaymentIntent::query()->find($existing->payment_intent_id)
                    : ($incomingReference !== null
                        ? PaymentIntent::query()->where('reference', $incomingReference)->where('provider', $provider)->first()
                        : null);
                $caseKey = hash('sha256', implode('|', [
                    $provider,
                    $eventId,
                    'webhook_payload_replay_conflict',
                    $payloadHash,
                ]));

                DB::transaction(function () use (
                    $provider, $eventId, $payloadHash, $existing, $intent,
                    $incomingReference, $incomingTransactionId, $caseKey
                ): void {
                    PaymentReconciliationCase::query()->firstOrCreate(
                        ['case_key' => $caseKey],
                        [
                            'provider' => $provider,
                            'reference' => $incomingReference ?? $existing->reference ?? $intent?->reference ?? 'unknown',
                            'payment_intent_id' => $intent?->id ?? $existing->payment_intent_id,
                            'case_type' => 'webhook_payload_replay_conflict',
                            'provider_status' => 'conflicting_replay',
                            'provider_transaction_id' => $incomingTransactionId,
                            'expected_amount_minor' => $intent?->amount_minor,
                            'expected_currency' => $intent?->currency,
                            'details' => [
                                'reason' => 'The same provider event ID was delivered with a different payload hash. The original event was not overwritten and no wallet credit was attempted.',
                                'event_id' => $eventId,
                                'original_payload_hash' => $existing->payload_hash,
                                'observed_payload_hash' => $payloadHash,
                                'original_event_outcome' => $existing->outcome,
                                'observed_provider_transaction_id' => $incomingTransactionId,
                                'requires_manual_review' => true,
                            ],
                            'status' => 'open',
                            'last_checked_at' => now(),
                        ],
                    );
                }, attempts: 3);

                throw new ConflictHttpException('Webhook event ID was replayed with a different payload; the conflict was recorded for review.');
            }

            // A previously committed "received" event should not permanently suppress
            // processing. Current settlements update the event in the same transaction,
            // so this branch is a safe recovery for legacy/incomplete records only.
            if ($existing->outcome === 'received') {
                $existing->delete();

                return $this->processVerifiedEvent($provider, $payload);
            }

            // Older deployments may have recorded a verified Paystack success as rejected
            // solely because the local checkout intent expired. Retry that exact event when
            // its intent is still pending; settlement remains protected by row locks and ledger idempotency.
            if ($provider === 'paystack' && $existing->outcome === 'rejected' && $existing->payment_intent_id !== null) {
                $intentStatus = PaymentIntent::query()->whereKey($existing->payment_intent_id)->value('status');
                if ($intentStatus === 'pending') {
                    $existing->delete();

                    return $this->processVerifiedEvent($provider, $payload);
                }
            }

            return ['outcome' => $existing->outcome, 'duplicate' => true];
        }
    }

    /**
     * Recover only a wallet-limit review after the caller has independently verified
     * Paystack's canonical transaction. Failed intents deliberately remain review-only.
     *
     * @param array<string, mixed> $transaction Canonical Paystack transaction data.
     * @return array{outcome:string, duplicate:bool}
     */
    public function recoverVerifiedReview(PaymentIntent $candidate, array $transaction): array
    {
        $reference = $transaction['reference'] ?? null;
        $transactionId = isset($transaction['id']) && is_numeric($transaction['id'])
            ? (string) $transaction['id'] : null;
        $amount = is_int($transaction['amount'] ?? null) ? $transaction['amount'] : null;
        $currency = is_string($transaction['currency'] ?? null) ? strtoupper($transaction['currency']) : null;
        $status = is_string($transaction['status'] ?? null) ? strtolower($transaction['status']) : null;

        if ($candidate->provider !== 'paystack'
            || $status !== 'success'
            || $reference !== $candidate->reference
            || $amount !== $candidate->amount_minor
            || $currency !== $candidate->currency
            || $transactionId === null) {
            throw new ConflictHttpException('Canonical Paystack transaction does not match the payment intent.');
        }

        return DB::transaction(function () use ($candidate, $transactionId): array {
            /** @var PaymentIntent|null $intent */
            $intent = PaymentIntent::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if ($intent === null || $intent->provider !== 'paystack') {
                throw new ConflictHttpException('Payment intent is no longer available for recovery.');
            }

            if (in_array($intent->status, ['paid', 'test_paid'], true)) {
                if ($intent->provider_transaction_id !== $transactionId) {
                    throw new ConflictHttpException('Payment intent was settled by a different provider transaction.');
                }

                if ($intent->status === 'paid') {
                    $this->resolveLateSuccessCases($intent, $transactionId);
                }

                return ['outcome' => 'duplicate_payment', 'duplicate' => true];
            }

            // This recovery is intentionally limited to the known wallet-cap exception.
            // It never changes a failed intent back to pending or paid.
            if ($intent->status !== 'review_required'
                || $intent->provider_transaction_id !== $transactionId) {
                return ['outcome' => 'late_success_review', 'duplicate' => false];
            }

            if (($intent->metadata['gateway_mode'] ?? null) !== 'live'
                || ! config('services.semizzy_payments.live_funding_enabled', false)) {
                return ['outcome' => 'funding_gate_closed', 'duplicate' => false];
            }

            try {
                $this->ledger->post(
                    walletId: $intent->wallet_id,
                    type: 'credit',
                    amountMinor: $intent->amount_minor,
                    idempotencyKey: 'funding:'.hash('sha256', 'paystack:'.$transactionId),
                    reference: $intent->reference,
                    // Reuse the exact canonical request payload used by initial
                    // settlement. The stable idempotency key must never be bypassed
                    // with altered metadata or a second credit to work around a conflict.
                    description: 'Verified wallet funding',
                    metadata: [
                        'payment_intent_id' => $intent->public_id,
                        'provider' => 'paystack',
                        'provider_transaction_id' => $transactionId,
                    ],
                    correlationId: (string) Str::uuid(),
                );
            } catch (DomainException $exception) {
                if (! str_contains($exception->getMessage(), 'account level would be exceeded')) {
                    throw $exception;
                }

                return ['outcome' => 'wallet_limit_review', 'duplicate' => false];
            }

            $intent->forceFill([
                'status' => 'paid',
                'provider_transaction_id' => $transactionId,
                'paid_at' => $intent->paid_at ?? now(),
            ])->save();

            // Case resolution shares the settlement transaction. A process interruption
            // cannot leave a successfully recovered wallet credit with stale open cases.
            $this->resolveLateSuccessCases($intent, $transactionId);
            $this->resolveWebhookProcessingRetryCases($intent, $transactionId);

            return ['outcome' => 'credited', 'duplicate' => false];
        }, attempts: 3);
    }

    private function resolveLateSuccessCases(PaymentIntent $intent, string $transactionId): void
    {
        PaymentReconciliationCase::query()
            ->where('provider', 'paystack')
            ->where('payment_intent_id', $intent->id)
            ->where('reference', $intent->reference)
            ->where('provider_transaction_id', $transactionId)
            ->where('case_type', 'late_success_review')
            ->where('status', 'open')
            ->get()
            ->each(function (PaymentReconciliationCase $case): void {
                $details = is_array($case->details) ? $case->details : [];
                $details['resolution'] = 'Canonical Paystack verification matched and wallet settlement is confirmed.';
                $details['resolved_at'] = now()->toISOString();
                $case->forceFill(['status' => 'resolved', 'details' => $details])->save();
            });
    }

    private function resolveWebhookProcessingRetryCases(PaymentIntent $intent, string $transactionId): void
    {
        PaymentReconciliationCase::query()
            ->where('provider', 'paystack')
            ->where('payment_intent_id', $intent->id)
            ->where('reference', $intent->reference)
            ->where('case_type', 'webhook_processing_retry')
            ->where('provider_transaction_id', $transactionId)
            ->where('status', 'open')
            ->lockForUpdate()
            ->get()
            ->each(function (PaymentReconciliationCase $case): void {
                $details = is_array($case->details) ? $case->details : [];
                $details['resolution'] = 'The same provider transaction was authoritatively settled; retry evidence is retained for audit.';
                $details['resolved_at'] = now()->toISOString();
                $details['resolved_after_settlement'] = true;
                $case->forceFill([
                    'status' => 'resolved',
                    'details' => $details,
                    'last_checked_at' => now(),
                ])->save();
            });
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}