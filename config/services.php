<?php

return [
    // Keep each provider's secret separate; never commit secret values.
    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
    ],
    'payments' => [
        'reconciliation_grace_minutes' => (int) env('PAYMENTS_RECONCILIATION_GRACE_MINUTES', 5),
    ],
    'semizzy_payments' => [
        'webhook_secrets' => [
            'semizzy' => env('SEMIZZY_WEBHOOK_SECRET'),
        ],
        'signature_tolerance_seconds' => (int) env('SEMIZZY_WEBHOOK_TOLERANCE_SECONDS', 300),
        'intent_ttl_minutes' => (int) env('SEMIZZY_PAYMENT_INTENT_TTL_MINUTES', 30),
    ],
];