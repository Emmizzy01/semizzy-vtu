<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\PaymentReconciliationCase;
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
            ->whereIn('status', ['pending', 'failed'])
            ->where('created_at', '<=', now()->subMinutes($graceMinutes))
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->chunkById(50, function ($intents) use (&$stats): void {
                foreach ($intents as $intent) {
                    $stats['checked']++;

                    try {
                        $transaction = $this->paystack->verifyTransaction($intent->reference);
                    } catch (Throwable $exception) {
                        $stats['unavailable']++;
                        Log::warning('Paystack reconciliation verification unavailable.', [
                            'reference' => $intent->reference,
                            'exception' => $exception::class,
                        ]);
                        continue;
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
                            continue;
                        }

                        if ($intent->status !== 'pending' || ($intent->expires_at && $intent->expires_at->isPast())) {
                            $this->recordCase($intent, 'late_success_review', $transaction, [
                                'reason' => 'Provider reports success after the intent was no longer eligible for automatic settlement.',
                            ]);
                            $stats['exceptions']++;
                            continue;
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

                        continue;
                    }

                    if (in_array($providerStatus, ['failed', 'abandoned'], true)) {
                        if ($intent->status === 'pending') {
                            PaymentIntent::query()->whereKey($intent->id)->where('status', 'pending')->update([
                                'status' => 'failed',
                                'updated_at' => now(),
                            ]);
                        }
                        $this->recordCase($intent, 'provider_failed', $transaction, [
                            'reason' => 'Provider verification reports a failed or abandoned transaction.',
                        ]);
                        $stats['failed']++;
                        continue;
                    }

                    $this->recordCase($intent, 'provider_pending', $transaction, [
                        'reason' => 'Provider verification has not confirmed a final successful payment.',
                    ]);
                    $stats['exceptions']++;
                }
            });

        return $stats;
    }

    /** @param array<string, mixed> $transaction @param array<string, mixed> $details */
    private function recordCase(PaymentIntent $intent, string $type, array $transaction, array $details): void
    {
        $transactionId = isset($transaction['id']) && is_numeric($transaction['id'])
            ? (string) $transaction['id'] : null;
        $key = hash('sha256', implode('|', [
            'paystack', $intent->reference, $type, $transactionId ?? 'no-transaction-id',
        ]));

        PaymentReconciliationCase::query()->updateOrCreate(
            ['case_key' => $key],
            [
                'provider' => 'paystack',
                'reference' => $intent->reference,
                'payment_intent_id' => $intent->id,
                'case_type' => $type,
                'provider_status' => is_string($transaction['status'] ?? null) ? $transaction['status'] : null,
                'provider_transaction_id' => $transactionId,
                'expected_amount_minor' => $intent->amount_minor,
                'observed_amount_minor' => is_int($transaction['amount'] ?? null) ? $transaction['amount'] : null,
                'expected_currency' => $intent->currency,
                'observed_currency' => is_string($transaction['currency'] ?? null) ? strtoupper($transaction['currency']) : null,
                'details' => $details,
                'status' => 'open',
                'last_checked_at' => now(),
            ],
        );
    }
}