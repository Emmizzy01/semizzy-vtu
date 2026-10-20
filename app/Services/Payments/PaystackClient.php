<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Services\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;

class PaystackClient
{
    private function secretKey(?string $requiredMode = null): string
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

        $mode = (string) app(IntegrationSettings::class)->get(
            'paystack.mode',
            str_starts_with($key, 'sk_test_') ? 'test' : 'live',
        );
        $expectedPrefix = $mode === 'test' ? 'sk_test_' : ($mode === 'live' ? 'sk_live_' : null);
        if ($expectedPrefix === null || ! str_starts_with($key, $expectedPrefix)) {
            throw new LogicException('The selected Paystack mode does not match the configured secret key. Choose the matching mode and key before processing payments.');
        }

        if ($requiredMode !== null && $mode !== $requiredMode) {
            throw new LogicException('The funding intent gateway mode no longer matches the active Paystack configuration. Retry only after reconciling the existing intent.');
        }

        return $key;
    }

    public function mode(): string
    {
        $key = $this->secretKey();

        return (string) app(IntegrationSettings::class)->get(
            'paystack.mode',
            str_starts_with($key, 'sk_test_') ? 'test' : 'live',
        );
    }

    public function initialize(PaymentIntent $intent, string $email): array
    {
        $intentMode = $intent->metadata['gateway_mode'] ?? null;
        if (! in_array($intentMode, ['test', 'live'], true)) {
            throw new LogicException('Funding intent has no valid gateway mode; checkout initialization is blocked.');
        }

        try {
            $response = Http::withToken($this->secretKey($intentMode))
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

    public function verifyTransaction(string $reference, ?string $requiredMode = null): array
    {
        try {
            $response = Http::withToken($this->secretKey($requiredMode))
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

    /** @return array<int, array<string, mixed>> */
    public function listNigerianBanks(): array
    {
        try {
            $response = Http::withToken($this->secretKey())
                ->acceptJson()->timeout(15)
                ->get('https://api.paystack.co/bank', [
                    'country' => 'nigeria', 'perPage' => 100, 'use_cursor' => 'false',
                ]);
        } catch (ConnectionException $exception) {
            throw new LogicException('Bank directory is temporarily unavailable.', previous: $exception);
        }

        if (! $response->successful() || $response->json('status') !== true || ! is_array($response->json('data'))) {
            throw new LogicException('Bank directory could not be loaded.');
        }

        return array_values(array_filter($response->json('data'), static fn ($bank) =>
            is_array($bank) && is_string($bank['name'] ?? null) && is_string($bank['code'] ?? null)
        ));
    }

    /** @return array{account_name: string, account_number: string} */
    public function resolveNigerianAccount(string $accountNumber, string $bankCode): array
    {
        if (! preg_match('/^\\d{10}$/', $accountNumber) || ! preg_match('/^[A-Za-z0-9_-]{2,20}$/', $bankCode)) {
            throw new \InvalidArgumentException('Bank code or 10-digit account number is invalid.');
        }

        try {
            $response = Http::withToken($this->secretKey())
                ->acceptJson()->timeout(15)
                ->get('https://api.paystack.co/bank/resolve', [
                    'account_number' => $accountNumber, 'bank_code' => $bankCode,
                ]);
        } catch (ConnectionException $exception) {
            throw new LogicException('Account name verification is temporarily unavailable.', previous: $exception);
        }

        $data = $response->json('data');
        if (! $response->successful() || $response->json('status') !== true || ! is_array($data)
            || ! is_string($data['account_name'] ?? null) || trim($data['account_name']) === ''
            || (string) ($data['account_number'] ?? '') !== $accountNumber) {
            throw new LogicException('The bank did not verify this account. Check the details and try again.');
        }

        return ['account_name' => trim($data['account_name']), 'account_number' => $accountNumber];
    }

    /** @return array<string, mixed> */
    public function createTransferRecipient(string $name, string $accountNumber, string $bankCode): array
    {
        try {
            $response = Http::withToken($this->secretKey())->acceptJson()->asJson()->timeout(15)
                ->post('https://api.paystack.co/transferrecipient', [
                    'type' => 'nuban', 'name' => $name, 'account_number' => $accountNumber,
                    'bank_code' => $bankCode, 'currency' => 'NGN',
                ]);
        } catch (ConnectionException $exception) {
            throw new LogicException('Bank recipient registration result is unknown; verify provider state before retrying.', previous: $exception);
        }

        $data = $response->json('data');
        if (! $response->successful() || $response->json('status') !== true
            || ! is_array($data) || ! is_string($data['recipient_code'] ?? null) || $data['recipient_code'] === '') {
            throw new LogicException('Paystack did not confirm the bank recipient registration.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function initiateBankTransfer(int $amountMinor, string $recipientCode, string $reference, string $reason): array
    {
        if ($amountMinor < 100 || $recipientCode === '' || $reference === '') {
            throw new \InvalidArgumentException('Payout amount, recipient and reference are required.');
        }

        try {
            $response = Http::withToken($this->secretKey())->acceptJson()->asJson()->timeout(20)
                ->post('https://api.paystack.co/transfer', [
                    'source' => 'balance', 'amount' => $amountMinor, 'recipient' => $recipientCode,
                    'reference' => $reference, 'reason' => mb_substr($reason, 0, 100),
                    'currency' => 'NGN',
                ]);
        } catch (ConnectionException $exception) {
            throw new LogicException('Bank payout result is unknown; reconcile the reference before any retry.', previous: $exception);
        }

        $data = $response->json('data');
        if (! $response->successful() || $response->json('status') !== true || ! is_array($data)
            || ($data['reference'] ?? null) !== $reference) {
            throw new LogicException('Paystack did not confirm the bank payout request.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function fetchBankTransfer(string $transferCode): array
    {
        if ($transferCode === '' || strlen($transferCode) > 120) {
            throw new \InvalidArgumentException('Provider transfer code is invalid.');
        }

        try {
            $response = Http::withToken($this->secretKey())->acceptJson()->timeout(15)
                ->get('https://api.paystack.co/transfer/'.rawurlencode($transferCode));
        } catch (ConnectionException $exception) {
            throw new LogicException('Bank payout status is temporarily unavailable.', previous: $exception);
        }

        $data = $response->json('data');
        if (! $response->successful() || $response->json('status') !== true || ! is_array($data)) {
            throw new LogicException('Paystack did not return the requested payout status.');
        }

        return $data;
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $expected = hash_hmac('sha512', $rawBody, $this->secretKey());

        return $signature !== '' && hash_equals($expected, strtolower($signature));
    }
}
