# Payment reconciliation and exceptions

## What runs

Run `php artisan payments:reconcile-paystack` to recheck aged pending and previously failed Paystack intents. Schedule this command every five minutes and configure the host's Laravel scheduler cron entry. The command uses the server-side Paystack transaction verification API; no browser-supplied status is trusted.

The default grace period is five minutes (`PAYMENTS_RECONCILIATION_GRACE_MINUTES`). The command checks up to 100 intents per run by default; use `--limit=200` to adjust within the enforced 1–500 bound.

## Outcomes

- **Verified success, matching reference/amount/currency, intent still eligible:** the existing funding service posts the wallet credit and ledger entries atomically with idempotency.
- **Provider pending/unknown:** create or refresh an open reconciliation case and check again on a later run.
- **Provider failed/abandoned:** mark a pending intent failed and create an open exception case. Previously failed intents remain eligible for later rechecks, so a provider-side late success is not silently lost.
- **Amount, currency, reference, or transaction identity mismatch:** create an open exception case; never auto-credit.
- **Success after intent expiry or after its state is no longer pending:** create a late-success review case; do not auto-credit.
- **Provider/network/configuration error:** log only the reference and exception class, leave intent state unchanged, and retry on a future run.

Cases are idempotently keyed by provider, reference, case type, and provider transaction ID. Review cases in `payment_reconciliation_cases`; resolve them only through an audited operational process. Do not manually edit balances to resolve a case. Any manual adjustment should use a dedicated, permission-controlled ledger adjustment workflow.

## Refunds and chargebacks

Signed Paystack refund and dispute events are recorded as open operational cases without automatically changing wallet balances. See [payment refunds and reversals](payment-reversals.md). Refund execution, chargeback recovery, and wallet compensating entries require a separate permission-controlled workflow.

## Scheduler

The command is registered in `routes/console.php` and scheduled every five minutes with overlap protection. On a typical Laravel host, ensure the system cron invokes `php artisan schedule:run` every minute. Shared-hosting deployments should configure the command and correct PHP executable/path in cPanel Cron Jobs if scheduler support is unavailable.

## Safety / release checklist

- Run migrations before enabling the command.
- Set `PAYSTACK_SECRET_KEY` only in protected server environment configuration.
- Set a suitable reconciliation grace period for your provider traffic.
- Monitor open cases, command failures, and growing pending-intent counts.
- Test with Paystack test keys before production.
- Late successes and mismatches require staff review; this foundation deliberately does not guess whether to credit, refund, or return a payment.
