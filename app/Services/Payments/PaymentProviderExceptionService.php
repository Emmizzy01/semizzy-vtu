<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\PaymentReconciliationCase;

class PaymentProviderExceptionService
{
    /**
     * Persist signed provider refund/dispute notifications for operational review.
     * These events never mutate wallet balances: refund settlement and chargebacks
     * need a separately authorized, policy-aware ledger workflow.
     *
     * @param array<string, mixed> $payload
     * @return array{outcome:string,case_key:string}
     */
    public function recordPaystackEvent(string $eventType, array $payload): array
    {
        if (! preg_match('/^(refund\.(pending|processing|needs-attention|failed|processed)|charge\.dispute\.(create|remind|resolve))$/', $eventType)) {
            throw new \InvalidArgumentException('Unsupported Paystack exception event.');
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = $data['transaction_reference'] ?? $data['reference'] ?? null;
        if (! is_string($reference) || $reference === '' || strlen($reference) > 100) {
            $reference = 'unmatched-'.substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 48);
        }

        $intent = PaymentIntent::query()
            ->where('provider', 'paystack')
            ->where('reference', $reference)
            ->first();

        $caseType = match ($eventType) {
            'refund.pending' => 'refund_pending',
            'refund.processing' => 'refund_processing',
            'refund.needs-attention' => 'refund_needs_attention',
            'refund.failed' => 'refund_failed',
            'refund.processed' => 'refund_processed',
            'charge.dispute.create' => 'chargeback_open',
            'charge.dispute.remind' => 'chargeback_reminder',
            'charge.dispute.resolve' => 'chargeback_resolved',
        };

        $providerObjectId = $data['refund_reference'] ?? $data['id'] ?? $data['dispute_id'] ?? null;
        $providerObjectId = is_scalar($providerObjectId) ? (string) $providerObjectId : null;
        $key = hash('sha256', implode('|', ['paystack', $reference, $caseType, $providerObjectId ?? 'no-object-id']));

        $amount = $data['amount'] ?? null;
        $amountMinor = is_int($amount) ? $amount : (is_string($amount) && ctype_digit($amount) ? (int) $amount : null);
        $currency = is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : null;
        $providerStatus = is_string($data['status'] ?? null) ? $data['status'] : $eventType;

        $case = PaymentReconciliationCase::query()->firstOrNew(['case_key' => $key]);
        $isNew = ! $case->exists;
        $case->fill([
            'provider' => 'paystack',
            'reference' => $reference,
            'payment_intent_id' => $intent?->id,
            'case_type' => $caseType,
            'provider_status' => substr($providerStatus, 0, 40),
            'provider_transaction_id' => $providerObjectId,
            'expected_amount_minor' => $intent?->amount_minor,
            'observed_amount_minor' => $amountMinor,
            'expected_currency' => $intent?->currency,
            'observed_currency' => $currency,
            'details' => [
                'event_type' => $eventType,
                'matched_payment_intent' => $intent !== null,
                'action_required' => true,
                'policy' => 'No automatic wallet debit, credit, or freeze is performed by event intake.',
            ],
            'last_checked_at' => now(),
        ]);
        if ($isNew) {
            $case->status = 'open';
        }
        $case->save();

        return ['outcome' => 'recorded_for_review', 'case_key' => $key];
    }
}
