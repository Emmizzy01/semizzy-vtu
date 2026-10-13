# Payment refunds, reversals, and chargebacks

## Scope delivered

SEMIZZY accepts Paystack's signed refund lifecycle events (`refund.pending`, `refund.processing`, `refund.needs-attention`, `refund.failed`, `refund.processed`) and dispute events (`charge.dispute.create`, `charge.dispute.remind`, `charge.dispute.resolve`). The webhook uses the same exact-raw-body HMAC-SHA512 validation as charge-success events.

Each supported notification creates or refreshes a durable row in `payment_reconciliation_cases`, matched to the local payment intent when the provider reference is present. Unmatched events are retained as review cases rather than discarded. Repeated notifications are idempotently keyed by provider, reference, event category, and provider refund/dispute ID when available.

## Critical financial boundary

This is exception intake, **not automatic refund execution or chargeback settlement**.

- A refund webhook does not debit a SEMIZZY wallet automatically.
- A refund-failed event does not credit a wallet automatically.
- A processed refund or chargeback does not rewrite the original funding ledger entry.
- A chargeback does not automatically freeze or debit a wallet.
- All cases remain visible as open operational exceptions until a future permission-controlled workflow resolves them with an audited compensating ledger transaction where required.

This boundary is intentional. Refunds may be partial; provider status changes over time; and customers may already have spent wallet funds. Automatic wallet debits can create negative-balance or double-reversal problems without a defined risk policy and a reserved-funds model.

## Operations

Review open cases in `payment_reconciliation_cases` and cross-check provider transaction/refund details in the Paystack Dashboard/API before taking action. Do not manually edit wallet balance columns or delete posted ledger entries. Build the authorized refund request/approval and compensating-entry workflow before enabling self-service refunds or automatic chargeback recovery.

## Verification and deployment

- Run database migrations before receiving these events.
- Set the Paystack webhook URL to the existing `/api/payments/webhooks/paystack` endpoint and enable the required refund and dispute events in the Paystack dashboard.
- Keep the secret key server-side only.
- Run the feature test suite in a configured Laravel environment.
- Verify with Paystack test mode before production.
