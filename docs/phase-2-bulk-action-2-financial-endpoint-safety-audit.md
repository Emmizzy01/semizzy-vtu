# Phase 2 — Bulk Action 2: Financial endpoint safety audit

## Scope

Audit HTTP entry points and recovery behavior for manual deposits, payment refunds, bank payouts, peer-wallet transfers, scheduled purchases, and single/bulk VTU purchases. Keep provider purchases and live financial activity gated.

## Route-to-control matrix

| Flow | HTTP entry points | Existing controls observed | Regression coverage |
|---|---|---|---|
| Manual deposit | `POST /wallet/manual-deposits` | Auth, 5/min throttle, amount/reference/evidence validation, feature flag and settings fail closed, evidence hashing, duplicate evidence detection, pending-only submission; wallet credit is deferred until staff review | `ManualDepositWorkflowTest` |
| Refund request / approval | `POST /wallet/refund-requests`, `POST /admin/payment-refunds/{refund}/approve` | Auth, request throttle, idempotency key, explicit `refunds.approve` capability, requester cannot approve own request, available-funds checks, provider-specific attempt tracking and replay-safe compensation | `PaymentRefundWorkflowTest`, refund reconciliation and MariaDB concurrency suites |
| Bank payout | `POST /wallet/bank-transfer` | Auth, 5/min throttle, 4-digit transaction PIN, required idempotency key, PIN lockout, provider-off default, verified beneficiary checks, uncertain outcomes held for reconciliation | `BankPayoutSafetyTest`, `BankPayoutMariaDbConcurrencyTest` |
| Peer transfer | `POST /wallet/transfers` | Auth, 5/min throttle, PIN, required idempotency key, self-transfer rejection, ownership-scoped history, atomic debit/credit and replay conflict detection | `PeerWalletTransferTest`, `PeerWalletTransferMariaDbConcurrencyTest` |
| Scheduled purchases | `POST/GET/DELETE /wallet/scheduled-purchases` | Auth, PIN and idempotency on creation, product-active validation, owner-scoped list/cancel, cannot cancel processing/unknown outcome | `ScheduledServicePurchaseTest` |
| Single/bulk VTU | `POST /wallet/vtu/orders`, `POST /wallet/vtu/orders/bulk`; recovery/status endpoints | Authenticated route group, throttles, server-side order validation, durable batch/job processing and explicit recovery paths | `VtuBulkPurchaseBatchTest`, provider recovery and purchase safety suites |

## Audit rules

- Reject guest access before validation or financial side effects.
- Verify owner scoping for histories, recovery/status lookups, beneficiary changes and scheduled purchases.
- Verify PIN before any debit/reservation or provider submission.
- Require idempotency for every mutation that can debit funds or dispatch a provider request.
- Never blind-retry a provider attempt with an unknown outcome; use exact-attempt reconciliation.
- Preserve double-entry ledger consistency and ensure duplicate webhooks/replays cannot duplicate credit/debit.
- Admin actions require their narrow capability, not merely a generic staff role.
- Do not perform real provider purchases, payouts, refunds or deposits during automated verification.

## Current limitations / closure gates

This document records the route and test coverage observed in the repository; it is not itself proof that the full suite passes. Close this bulk action only after targeted tests and the full PHP 8.3/8.4, SQLite/MariaDB security, and frontend build workflows pass on the exact final commit. Review any failing test before changing financial behavior.
