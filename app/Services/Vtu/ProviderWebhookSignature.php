<?php

namespace App\Services\Vtu;

class ProviderWebhookSignature
{
    /** Verify Sogo's X-Sogo-Signature-256 over the exact raw request body. */
    public function verifySogo(string $rawBody, string $signature, string $secret): bool
    {
        return $this->verifyRawBodyHmac($rawBody, $signature, $secret);
    }

    /** Verify Dataway's X-Dataway-Signature over the exact raw request body. */
    public function verifyDataway(string $rawBody, string $signature, string $secret): bool
    {
        return $this->verifyRawBodyHmac($rawBody, $signature, $secret);
    }

    /**
     * Verify GSUBZ's X-Gsubz-Signature = HMAC-SHA256(timestamp + "." + raw body).
     * The timestamp window limits replay opportunities; callers must still deduplicate event IDs.
     */
    public function verifyGsubz(
        string $rawBody,
        string $timestamp,
        string $signature,
        string $secret,
        ?int $now = null,
        int $toleranceSeconds = 300,
    ): bool {
        if ($secret === '' || ! preg_match('/^[0-9]{1,12}$/', $timestamp)
            || abs(($now ?? time()) - (int) $timestamp) > max(0, $toleranceSeconds)
            || ! preg_match('/^sha256=([a-f0-9]{64})$/i', trim($signature), $matches)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        return hash_equals($expected, strtolower($matches[1]));
    }

    private function verifyRawBodyHmac(string $rawBody, string $signature, string $secret): bool
    {
        if ($secret === '' || ! preg_match('/^sha256=([a-f0-9]{64})$/i', trim($signature), $matches)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($matches[1]));
    }
}
