<?php

namespace App\Services\Vtu;

class ProviderWebhookSignature
{
    /**
     * Validate Dataway's documented X-Dataway-Signature: sha256=<hex>
     * against the exact raw request body. Reject empty secrets and malformed headers.
     */
    public function verifyDataway(string $rawBody, string $signature, string $secret): bool
    {
        if ($secret === '' || ! preg_match('/^sha256=([a-f0-9]{64})$/i', trim($signature), $matches)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($matches[1]));
    }
}
