# Payment funding workflow

This is a provider-neutral, signed-webhook foundation. It does **not** initiate a real provider checkout yet. Do not tell customers an intent is payable until a provider adapter creates a checkout session and returns a provider-hosted checkout URL.

## Current flow

1. An authenticated customer submits `amount_minor` to `POST /wallet/funding-intents`. Amounts are integer minor units (kobo for NGN); the current allowed range is 100 to 500,000,000.
2. SEMIZZY creates a pending NGN payment intent with a UUID public ID, a unique `SZF-` reference, and an expiry.
3. A provider sends `POST /api/payments/webhooks/{provider}` with JSON and `X-Semizzy-Signature: t=<unix timestamp>,v1=<hex HMAC-SHA256>`.
4. The signature is calculated as `HMAC_SHA256(secret, timestamp + "." + exact_raw_request_body)`. Requests outside the configured timestamp tolerance are rejected before parsing or processing.
5. The normalized successful event must contain `id`, `type=payment.succeeded`, and `data.reference`, `data.transaction_id`, `data.status=succeeded`, `data.amount_minor`, and `data.currency`.
6. The backend checks the provider, pending status, expiry, exact amount and currency. It then marks the intent paid and posts the wallet credit plus ledger/audit records in the same database transaction.
7. `(provider,event_id)` uniqueness blocks event replay. The wallet ledger idempotency key also prevents a provider transaction from crediting twice. Reusing an event ID with a different payload is rejected.

## Environment configuration

Set these only in the deployment environment (never commit secrets):

- `SEMIZZY_WEBHOOK_SECRET`: high-entropy secret for the `semizzy` webhook provider; add separate configuration entries for each additional provider rather than reusing one secret.
- `SEMIZZY_WEBHOOK_TOLERANCE_SECONDS`: accepted timestamp skew, default 300 seconds.
- `SEMIZZY_PAYMENT_INTENT_TTL_MINUTES`: pending intent lifetime, default 30 minutes.

The generic `X-Semizzy-Signature` scheme is a development contract, not the signature format of any particular payment gateway. Before connecting Paystack, Flutterwave, or another provider, implement that provider's documented signature verification and event normalization in a dedicated adapter; do not assume this generic header matches theirs.

## Important operational constraints

- Browser redirects are never proof of payment and must not credit a wallet.
- Keep webhook endpoints HTTPS-only in production and use provider-specific secrets; rotate compromised secrets.
- Use MySQL/MariaDB with InnoDB for production row-locking and unique constraints.
- Configure provider amount/currency semantics carefully. This workflow expects amount in integer minor units; convert and validate at the adapter boundary.
- Monitor rejected, expired, mismatched, and repeatedly failing events. Provider reconciliation is still required.
- Checkout-session creation, provider API authentication, refund/reversal handling, and live-provider sandbox tests are not included yet. Do not use this foundation for live money until the provider adapter and end-to-end verification are complete.
