# Payment funding workflow

## Paystack integration

The first concrete provider adapter is Paystack. Configure `PAYSTACK_SECRET_KEY` and `PAYSTACK_CALLBACK_URL` in the deployment environment; never expose the secret key to React, Inertia props, browser JavaScript, or public responses.

### Checkout

An authenticated customer submits `amount_minor` to `POST /wallet/funding-intents`. The server creates a pending NGN intent and calls Paystack's `POST /transaction/initialize` endpoint using the secret key, the authenticated user's email, the exact amount in kobo, currency, and SEMIZZY's unique reference. The API returns a provider-hosted `checkout_url`.

A successful initialization is not a payment. The intent remains pending and the wallet balance does not change.

### Webhook and transaction verification

Paystack webhook endpoint: `POST /api/payments/webhooks/paystack`.

1. Verify `x-paystack-signature` as HMAC-SHA512 of the exact raw request body, keyed with the Paystack secret key, using a constant-time comparison.
2. Only process `charge.success` events.
3. Call Paystack's server-side `GET /transaction/verify/{reference}` endpoint with the secret key.
4. Require the verified transaction to be successful and match the webhook transaction ID, reference, amount, and currency.
5. The existing funding service then locks the payment intent and checks provider, pending state, expiry, exact intent amount and currency.
6. Mark the intent paid and post the credit, ledger entry, and audit event in the same database transaction.
7. Use a stable provider transaction-based event ID and ledger idempotency key to prevent duplicate credits.

The browser return endpoint only asks Paystack for status and reports SEMIZZY's current intent state. It never credits a wallet. The signed webhook flow remains the settlement path.

## Configuration

- `PAYSTACK_SECRET_KEY`: use a Paystack test secret in development/staging and the production secret only in a protected production environment.
- `PAYSTACK_CALLBACK_URL`: HTTPS callback URL in deployed environments.
- `SEMIZZY_WEBHOOK_SECRET`: only for the separate generic SEMIZZY development webhook endpoint.
- `SEMIZZY_WEBHOOK_TOLERANCE_SECONDS` and `SEMIZZY_PAYMENT_INTENT_TTL_MINUTES`: generic webhook timestamp and intent expiry settings.

Do not log secret keys or send them to the browser. Restrict access to production environment variables and rotate credentials if exposed.

## Tests and release gate

`tests/Feature/PaystackFundingWorkflowTest.php` uses Laravel's HTTP fake to simulate Paystack checkout initialization and transaction verification. It covers a successful signed webhook, invalid signature, duplicate delivery, and amount mismatch. These are mocked integration tests, not a live Paystack sandbox run.

Before live money is enabled:
- Run the full Laravel test suite against the configured test database.
- Use Paystack test keys and the provider dashboard/webhook tooling to test real sandbox events.
- Confirm the deployed callback and webhook URLs are HTTPS and reachable.
- Add monitoring and reconciliation for pending, failed, mismatched, and late payments.
- Implement refund/reversal processing and operational alerts.
- Keep MySQL/MariaDB InnoDB in production for row locks and uniqueness constraints.

Do not trust browser redirects or client-submitted payment status as proof of payment.
