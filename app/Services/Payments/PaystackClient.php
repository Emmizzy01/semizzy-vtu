<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;

class PaystackClient
{
    private function secretKey(): string
    {
        $key = (string) config('services.paystack.secret_key', '');

        if ($key === '') {
            throw new LogicException('Paystack secret key is not configured.');
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
                    'callback_url' => (string) config('services.paystack.callback_url', ''),
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

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $expected = hash_hmac('sha512', $rawBody, $this->secretKey());

        return $signature !== '' && hash_equals($expected, strtolower($signature));
    }
}
