# Phase 3 education transaction architecture

## Current slice

- Adds durable education order and provider-attempt records with per-user idempotency uniqueness and unique attempt numbering.
- Adds a provider contract requiring candidate validation, single submission with a stable order reference, and lookup against that same identity.
- Adds a fail-closed provider registry: catalogue provider labels never cause fallback to a different provider.
- Adds purchase preflight checks for feature status, approved product, provider adapter, secure configuration, and positive candidate validation.
- No education purchase route is exposed by this slice. The preflight service intentionally does not debit a wallet or submit a provider request.

## Required before purchase release

1. Implement a provider adapter only after the provider's live product, candidate-validation, idempotency, lookup and refund/reversal contracts are confirmed.
2. Implement the order coordinator using WalletLedgerService::post for the debit and definitive-failure refund; commit the order, debit transaction ID, dispatch marker, and provider-attempt row atomically before network submission.
3. On timeout or unknown status, retain the debit and reconcile only against the persisted provider and exact original order reference. Never blind-retry or route to another provider.
4. Make success/failure transitions idempotent under row locks; refund only a definitive failure, using a unique stable refund idempotency key.
5. Add tests for duplicate submissions, reused idempotency keys with different payloads, concurrent workers, crash after reservation/before submit, provider timeout, exact-provider lookup, and refund exactly once.
6. Verify candidate data minimization, encrypted/safe response storage, receipt/support display, admin permissions and audit events.
7. Run the schema audit and review migrations before any production migration. Keep feature/product purchase gates closed until these gates pass.

No production database migration, provider call, wallet debit, or live purchase was performed by this change.
