<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use LogicException;

class WebhookSignatureVerifier
{
    public function verify(Request $request, string $provider): void
    {
        $secret = (string) config('services.semizzy_payments.webhook_secrets.'.$provider, '');
        if ($secret === '') {
            throw new LogicException('Payment webhook verification is not configured for this provider.');
        }

        $header = (string) $request->header('X-Semizzy-Signature', '');
        if (! preg_match('/(?:^|,)t=(\d+)(?:,|$)/', $header, $timeMatch)
            || ! preg_match('/(?:^|,)v1=([a-f0-9]{64})(?:,|$)/i', $header, $signatureMatch)) {
            abort(401, 'Invalid webhook signature header.');
        }

        $timestamp = (int) $timeMatch[1];
        $tolerance = max(30, (int) config('services.semizzy_payments.signature_tolerance_seconds', 300));
        if (abs(now()->timestamp - $timestamp) > $tolerance) {
            abort(401, 'Webhook timestamp is outside the allowed window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, strtolower($signatureMatch[1]))) {
            abort(401, 'Webhook signature verification failed.');
        }
    }
}