<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Services\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;

class PaystackClient
{
    private function secretKey(): string
    {
        $key = (string) app(IntegrationSettings::class)->get('paystack.secret_key', config('services.paystack.secret_key', ''));

        if ($key === '') {
            throw new LogicException('Paystack secret key is not configured.');
        }

        // Paystack secret keys determine the account mode. Reject malformed values
        // rather than sending an unknown credential to the live API.
        if (! preg_match('/^sk_(?:test|live)_[^\\s]+$/', $key)) {
            throw new LogicException('Paystack secret key must be a valid test or live secret key.');
        }

        return $key;
    }

    public function initialize(PaymentIntent $intent, string $email): array
    {
        try {
            $response = Http::withToken($this->secretKey())
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post('https://api.paystack.co/transaction/initialize', [
                    'email' => $email,
                    'amount' => $intent->amount_minor,
                    'currency' => $intent->currency,
                    'reference' => $intent->reference,
                    'callback_url' => (string) app(IntegrationSettings::class)->get('paystack.callback_url', config('services.paystack.callback_url', '')),
                    'metadata' => [
                        'payment_intent_id' => $intent->public_id,
                        'semizzy_reference' => $intent->reference,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new LogicException('Paystack checkout initialization is temporarily unavailable.', previous: $exception);
        }

        $authorizationUrl = $response->json('data.authorization_url');
        $host = is_string($authorizationUrl) ? parse_url($authorizationUrl, PHP_URL_HOST) : null;
        if (! $response->successful() || $response->json('status') !== true
            || ! is_string($authorizationUrl)
            || parse_url($authorizationUrl, PHP_URL_SCHEME) !== 'https'
            || $host !== 'checkout.paystack.com'
            || $response->json('data.reference') !== $intent->reference) {
            throw new LogicException('Paystack could not initialize this checkout.');
        }

        return $response->json('data');
    }

    public function verifyTransaction(string $reference): array
    {
        try {
            $response = Http::withToken($this->secretKey())
                ->acceptJson()
                ->timeout(15)
                ->get('https://api.paystack.co/transaction/verify/'.rawurlencode($reference));
        } catch (ConnectionException $exception) {
            throw new LogicException('Paystack transaction verification is temporarily unavailable.', previous: $exception);
        }

        if (! $response->successful() || $response->json('status') !== true
            || ! is_array($response->json('data'))) {
            throw new LogicException('Paystack transaction verification failed.');
        }

        return $response->json('data');
    }

    /**
     * Initiate a full or partial refund against a verified Paystack transaction.
     * Amounts are minor currency units (kobo for NGN).
     *
     * @return array<string, mixed>
     */
    public function refund(string $transactionReference, int $amountMinor, string $reason): array
    {
        if ($transactionReference === '' || strlen($transactionReference) > 150 || $amountMinor < 1) {
            throw new \InvalidArgumentException('Refund transaction and amount are invalid.');
        }

        try {
            $response = Http::withToken($this->secretKey())
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post('https://api.paystack.co/refund', [
                    'transaction' => $transactionReference,
                    'amount' => $amountMinor,
                    'customer_note' => substr($reason, 0, 500),
                    'merchant_note' => 'SEMIZZY approved refund',
                ]);
        } catch (ConnectionException $exception) {
            // A timeout is ambiguous: Paystack may have accepted the refund.
            throw new LogicException('Paystack refund result is unknown; reconcile before retrying.', previous: $exception);
        }

        if (! $response->successful() || $response->json('status') !== true
            || ! is_array($response->json('data'))) {
            throw new LogicException('Paystack did not confirm the refund request.');
        }

        return $response->json('data');
    }

    /**
     * Fetch an existing Paystack refund by its provider-issued refund ID.
     *
     * @return array<string, mixed>
     */
    public function fetchRefund(string $refundId): array
    {
        if ($refundId === '' || ! ctype_digit($refundId)) {
            throw new \InvalidArgumentException('Paystack refund ID must be numeric.');
        }

        try {
            $response = Http::withToken($this->secretKey())
                ->acceptJson()
                ->timeout(15)
                ->get('https://api.paystack.co/refund/'.rawurlencode($refundId));
        } catch (ConnectionException $exception) {
            throw new LogicException('Paystack refund status is currently unavailable.', previous: $exception);
        }

        if (! $response->successful() || $response->json('status') !== true
            || ! is_array($response->json('data'))) {
            throw new LogicException('Paystack did not return the requested refund.');
        }

        return $response->json('data');
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $expected = hash_hmac('sha512', $rawBody, $this->secretKey());

        return $signature !== '' && hash_equals($expected, strtolower($signature));
    }
}
