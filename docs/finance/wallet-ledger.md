# Wallet ledger: implementation contract

## Current scope

The first wallet slice implements wallet account provisioning and atomic posting of credits/debits. It is an internal foundation only: no public wallet mutation route and no payment-provider integration is enabled.

## Amounts and currencies

- Amounts are positive integer minor units, never floating point.
- For NGN, 100 minor units represent ₦1.00.
- A wallet is unique per user and currency.
- The current account balance is a transactional projection; the ledger entry is the durable record of each posted mutation.

## Posting invariants

```text
active wallet + valid operation
  -> lock wallet row
  -> check idempotency key and request hash
  -> validate available balance / overflow
  -> insert pending transaction
  -> update wallet balance and version
  -> insert one ledger entry
  -> mark transaction posted
  -> append audit event
  -> commit all database changes together
```

- WalletLedgerService::post runs these steps inside a database transaction and requests up to three attempts for retryable transaction failures.
- A row-level SELECT FOR UPDATE is used through Eloquent lockForUpdate(). Use MySQL/MariaDB with transactional InnoDB tables in production; SQLite does not provide equivalent row-lock semantics.
- The unique (wallet_id, idempotency_key) constraint is the database backstop against duplicate posting.
- A repeated key with the same request hash returns the original transaction. Reusing the key with a different financial request throws IdempotencyConflict.
- A failed debit rolls back the balance, transaction, and ledger entry. A rejection audit event is written after rollback.
- The unique wallet_transaction_id constraint allows at most one ledger entry per wallet transaction.

## Critical integration rules

1. Never expose WalletLedgerService::post as a client-controlled generic credit endpoint.
2. Credit a wallet only after a trusted funding workflow verifies the payment provider's signed notification or independently confirms payment status. Never trust the browser redirect alone.
3. Debit wallets only inside the same server-side business workflow that reserves/records the order and establishes the appropriate order state.
4. Use provider references and stable idempotency keys generated from a trusted operation identifier.
5. Do not edit or delete posted ledger entries. Corrections should be compensating transactions with their own references and audit trail.
6. Do not use floating-point arithmetic for amounts. Convert and validate monetary input at the boundary.
7. Keep the ledger and wallet mutations on a transactional database connection and use InnoDB for MySQL/MariaDB.
8. Add reconciliation, immutable audit retention, authorization policies, limits, and concurrency tests before real funds are enabled.

## Verification

The feature test suite covers credit posting, duplicate requests, idempotency-key conflicts, insufficient-funds rollback, and debit balance changes. Run composer install and php artisan test in a configured environment before considering this slice verified. No runtime test result is claimed by this commit.