# VTU service-order lifecycle

This document defines the first contract for airtime, data, and other VTU orders. The status enum is intentionally separate from provider-specific integrations so provider adapters can be added without inventing their own state transitions.

## Statuses

- `pending`: the order has been accepted locally but has not been dispatched.
- `processing`: dispatch has begun; cancellation is no longer safe by default.
- `successful`: the provider result has been verified as successful.
- `failed`: the provider has returned a definitive failure.
- `awaiting_reconciliation`: the provider result is ambiguous (for example, a timeout after dispatch). Do not blindly submit a second purchase.
- `cancelled`: the order was cancelled before dispatch.

## Allowed transitions

- `pending -> processing | cancelled`
- `processing -> successful | failed | awaiting_reconciliation`
- `awaiting_reconciliation -> successful | failed`

Successful, failed, and cancelled orders are terminal. An ambiguous result must be reconciled against the provider before it is marked successful or failed. A retry must not silently create a second provider purchase; retries and duplicate client requests must use an idempotency strategy in the order service.

## Money and delivery safety

- Store NGN amounts as integer minor units (kobo), never floating point.
- Debit the wallet through the existing ledger service, not by updating wallet balances directly.
- Record provider request identifiers and responses with secrets redacted.
- Verify provider callbacks and reconcile timeouts before finalizing an order.
- Keep provider credentials in server-side configuration; never expose them to Inertia props or API responses.
- Add database persistence, wallet reservation/debit policy, provider adapter contracts, authorization, and feature tests in the next implementation slice. Do not treat this status enum alone as a complete VTU purchasing feature.
