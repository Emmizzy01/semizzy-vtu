# Phase 1 remaining blueprint gap audit

**State:** Open. This is an implementation audit, not sign-off or a test report. The source tree and owner-approved blueprint were reviewed after the passkey foundation batch. No theme changes or live financial/provider operations were performed.

**Latest security follow-up (commit `e861f653ff7229faa60be7ecf505d3cf763edafa`):** passkey origin validation now rejects wildcard hosts, and `tests/Feature/PasskeyOriginAndConfirmationRegressionTest.php` adds regression coverage for wildcard-origin rejection, expired confirmation rejection, and recent confirmation acceptance. The tests have been committed but have **not** been executed in this environment; the commit has no reported status checks/workflow runs available through the current GitHub integration. Do not treat this as test-pass evidence or Phase 1 sign-off.

## Highest-priority remaining gaps

| Priority | Blueprint capability | Current evidence / gap | Required next action before acceptance |
|---|---|---|---|
| P0 | WebAuthn/passkeys | Server/client packages, credential migration, feature gate, registration/deletion UI and recent-password confirmation are in code. Dependencies have not been installed/resolved here; migration and browser ceremonies have not been exercised. | Install dependencies, run migration in a disposable test database, verify registration/assertion/rejection/session rotation in real browsers. Keep PASSKEYS_ENABLED=false until production HTTPS/RP/origin values are checked. |
| P0 | SMS/WhatsApp OTP | Generic HTTPS JSON delivery adapter exists, but no vendor-specific API contract, delivery receipt/status handling, provider credentials or live device delivery has been verified. | Select approved vendor per channel, implement its exact API/template/status contract, add mocked contract tests and delivery-status recovery. Keep both channels disabled until onboarding and live verification. |
| P0 | Wallet/PIN safety | Purchase paths include PIN verification and idempotency patterns, but the blueprint requires PIN/limits/risk enforcement across every applicable sensitive endpoint. Current register still has no final endpoint-by-endpoint acceptance evidence. | Inventory every wallet debit, payout, refund, scheduled execution, bulk item, funding approval and credential/security change. Prove server-side PIN/authorization, locking, idempotency and audit behavior for each. |
| P0 | Dedicated virtual accounts | Existing service is Paystack-only and reserves one account per user/provider. Blueprint requires two or more distinct accounts per user across supported banks/providers where available. | Add additional provider adapters only after reviewed contracts; persist each assignment independently; test ambiguous provisioning recovery and exactly-once deposit settlement. Keep provisioning disabled. |
| P1 | VTU catalogue/provider imports | Provider readiness status, provider-specific admin filtering, GSUBZ plain-`id` flattening, nested mapping visibility, and customer-facing provider-identity privacy are implemented. Sogo/GSUBZ/Dataway imports fetch the complete category snapshot before writes and now apply each category within a retried database transaction; imports remain inactive/unapproved, and unsupported categories stay catalogue-only. Mock coverage includes repeated/idempotent imports across VTPass/Sogo/GSUBZ/Dataway, malformed/empty responses, partial-response timeouts, provider readiness/filtering, and customer-catalogue privacy. On commit `938b9b6ea41919d8f5eceb9d6d6bb550722906fa`, the frontend production build passed; PHP 8.3 and PHP 8.4 each passed lint, targeted VTU routing tests, education/concurrency tests, focused provider catalogue tests, and the full suite (583 tests, 4,199 assertions per PHP version). A follow-up change makes Sogo/GSUBZ/Dataway category writes atomic; CI for that exact change is pending. Live provider response contracts remain unaccepted. | After the atomic-import change passes CI, review provider-specific purchase/lookup/recovery adapters against each provider's official live contract and sandbox/live evidence. Do not activate live purchases until idempotency, timeout/unknown-outcome recovery, status lookup and duplicate-submission behavior are independently verified. Continue verifying separate customer options and ensure provider names, internal IDs, provider costs, and routing metadata never leave the server. |
| P1 | Biller/account verification | Registry currently has a VTPass biller-verification adapter; additional provider coverage is not established. | Add and test adapters only where the real provider contract supports the required verification. Keep inconclusive verification distinct from verified and block services requiring verification when result is unknown. |
| P1 | Dynamic data catalogue | Customer response allowlists validity/data-type metadata, but end-to-end coverage of SME, corporate/gifting, direct, provider-defined types and all durations is not accepted yet. | Test 1-day, night, 3-day, weekly, monthly and provider-defined durations; preserve independent offers with duplicate plan/price; never expose provider identity. |
| P1 | Government document registration, number verification, SMM, social media accounts, betting platforms | The Phase 1 register records these categories as a scope audit gap; no complete real provider-backed end-to-end flows are accepted here. | Define each approved service contract, catalogue, verification, pricing, order state, provider failure recovery, reconciliation and customer/admin workflow. Do not submit fake orders or label roadmap pages as live. |
| P1 | User types and business/team roles | Account type, transaction level and staff permission records exist, but the blueprint's full Customer, Reseller, Agent, Business/team, Support, Finance/Ops, Compliance/Security and admin permission boundaries need a matrix-based audit. | Build a route/action permission matrix, prove least-privilege server enforcement, and test self-escalation, cross-tenant team access, sensitive financial separation of duties and tier/limit enforcement. |
| P1 | Bulk purchase and scheduled purchases | Bulk VTU has durable batch/item records and a queued processor, while scheduled purchase code exists. Acceptance is still pending for supported-service coverage, worker recovery, unknown provider outcomes, downtime and concurrency. | Test 10–100 items, partial outcomes, worker interruption, idempotent re-dispatch, pricing/recipient revalidation, schedule pause on unknown status and no catch-up bursts. Do not extend to unsupported service categories. |
| P1 | Site-wide settings / feature dependency | Site settings, feature controls and add-on dependency registry exist, but every blueprint setting and every dependency boundary has not been checked. | Compare each setting with its UI, authorization, validation, audit reason, old/new hash, dependent feature behavior and maintenance recovery path. A toggle must not activate an unimplemented service. |
| P1 | Privacy/data portability and retention | Privacy request intake and a basic account-scoped export exist; the export explicitly is not full data portability. Retention policies remain disabled and automatic deletion is not implemented. Incident workflows do not establish external notification delivery. | Define export coverage, legal review/retention rules, approved deletion execution and regulator/customer/on-call notification policy before enabling those actions. |
| P2 | Dashboard and global navigation | Dashboards, admin settings and navigation are present, but a visible link is not acceptance. | Verify every dashboard metric is derived from persisted state and every link resolves to a permission-protected working workflow; add empty/error/unauthorized-state coverage. |
| P2 | Physical listings / Nigerian locations from owner-approved scope | The owner-approved project context includes all Nigerian states/locations and new/used flags for physical listings; this is not established as a completed Phase 1 capability by the current register. | Confirm marketplace phase ownership and schema scope; add the state/location catalogue and listing condition only in the approved marketplace workstream. |

## Security and release invariants

- Keep passkeys, SMS/WhatsApp delivery, virtual-account provisioning and provider purchases disabled until their own readiness gates pass.
- Never bypass configured OTP/TOTP with passwordless passkey login. Current passkey authorization intentionally rejects passkey login while those separate step-up methods are enabled; an integrated step-up handoff is still required for that combination.
- No real payment, payout, refund, deposit credit or service purchase is an acceptable substitute for automated tests.
- Final test sweep is still pending under the owner's implementation-first instruction. No Phase 1 acceptance is implied by this document.
- Production acceptance must include additive migrations, dependency installation, cPanel schema verification, first-admin permission setup, queue/scheduler configuration and provider onboarding evidence, without resetting production data.

## Suggested next implementation order

1. Finish the passkey package/dependency/migration/browser integration acceptance work.
2. Complete provider catalogue import and dynamic data-plan verification across VTPass, Sogo, GSUBZ and Dataway, including the new provider filter/readiness UI and GSUBZ identifier regression coverage, without enabling live purchases.
3. Perform the endpoint-by-endpoint PIN/idempotency/permission audit for all wallet-affecting actions.
4. Continue the real-provider service gap inventory (government document registration, number verification, SMM, social accounts, betting) and implement only reviewed contracts.
5. Run the planned PHP, concurrency, frontend typecheck/build and real-browser acceptance sweep after the implementation sweep is complete.


## Endpoint security review — wallet writes (2026-10-20)

This review is source inspection only; it is not runtime or concurrency-test evidence.

### Controls observed in current source

- Peer wallet transfer route is authenticated by the surrounding customer route group and rate-limited to 5 requests/minute. The controller requires a four-digit transaction PIN and an idempotency key. The service rejects self-transfer, binds an idempotency key to a request hash, posts sender debit and recipient credit in one database transaction, and locks both wallet rows in deterministic ID order. The ledger checks available balance under a wallet row lock and records posted/rejected audit events.
- Bank payout submission is authenticated and rate-limited to 5 requests/minute. The controller requires a four-digit PIN and idempotency key. The service verifies beneficiary ownership/status, checks the admin payout feature toggle, debits via the wallet ledger inside a transaction, returns an existing idempotent payout without resubmitting it, and marks an uncertain provider result for reconciliation rather than blindly retrying.
- Refund request handling locks the customer and payment-intent rows, confirms the paid intent belongs to the requester, limits total reserved refunds to the original payment amount, and binds the idempotency key to the request details. Refund approval is permission-gated at the route and the service rejects self-approval.
- Wallet ledger posts use a wallet row lock, request-hash idempotency, active-wallet checks, insufficient-funds enforcement, and an audit event in the same transaction.

### Remaining gaps / verification blockers

1. These controller/service paths have not been executed in the current environment. Add or run tests proving concurrent duplicate P2P transfer and payout requests create exactly one debit and one business record; key reuse with changed amount/beneficiary is rejected; insufficient balance creates no debit; and unknown provider outcome is reconciliation-only with no automatic second submission.
2. TransactionPinController::update currently permits initial PIN setup for an authenticated account without checking a current PIN (because none exists), and subsequent changes use current PIN but not an additional recent-password confirmation. Decide and implement an explicit step-up policy for first-time setup and PIN changes, with audit logging and tests; do not silently change the customer flow without matching the security UX.
3. BankPayoutService::addBeneficiary calls the provider to create a recipient before checking whether the returned provider recipient code is already associated with another Semizzy user. This can create an orphaned provider recipient on a rejected cross-account association. Add a safe recovery/cleanup policy or a preflight provider-recipient deduplication mechanism and tests, without exposing full bank account data.
4. PIN verification and the financial operation are separate transactions: PIN lock state is updated before the wallet transaction starts. Keep reviewing race conditions across PIN verification, user/session state, and financial writes; document the accepted policy and test the relevant concurrency boundary.
5. Route-level throttling and feature gates are useful but do not replace authorization/ownership checks inside services. Continue the endpoint inventory for VTU single/bulk orders, scheduled purchases, manual-deposit review/approval, funding webhooks, refund approval, virtual-account provisioning, credential/security changes, and all admin funding actions.

### Acceptance status

- Source inspection: completed for the P2P transfer controller/service, bank payout controller/service, wallet ledger, PIN service, and refund controller/service.
- Runtime feature tests and concurrency tests: **not executed here**; no pass claim.
- Production transactions/provider calls: **not performed**.
- Phase 1: **not signed off**. Keep live provider purchases, live payouts, and other live financial operations gated until test evidence and provider reconciliation contracts are verified.

### VTU single/bulk purchase endpoint review

Source inspection of VtuOrderController confirms:
- Single purchase requires an authenticated user, configured transaction PIN, supplied four-digit PIN, a non-empty idempotency key, service feature checks, and server-side order-service validation. PIN verification has a 15-minute lock after five failed attempts.
- Bulk purchase requires a transaction PIN and a batch key, accepts 10–100 items, limits current bulk eligibility to active NGN airtime/data products, resolves customer selections to internal product codes server-side, derives stable per-item idempotency keys, hashes the batch request, handles the user+batch-key uniqueness race, and dispatches a batch job only after persisting the batch and items.
- Bulk retry with the same key and same request returns the existing batch; reuse with different request details returns conflict. A separate recovery/status endpoint exists, but ownership and status behavior still require execution tests.

Still unverified: test that repeated bulk requests and concurrent duplicate keys never create duplicate batch records, duplicate wallet debits, or duplicate provider submissions; partial failures remain item-scoped and recoverable; a batch status/recovery request cannot read another user's batch; inactive products and altered prices cannot bypass server-side checks; and the worker never retries an unknown provider outcome blindly. The runtime test suite was not executable through the current environment, so none of these are marked passed.

### Regression coverage added — account isolation

- Added a peer-transfer history feature test proving unrelated users see no transfer rows while sender and recipient can each see the same transfer with their own direction. This complements existing service tests for same-key duplicate transfers, changed-payload key conflicts, and insufficient-funds rollback.
- Added a bank-payout history feature test proving each authenticated user sees only their own payout history rows. Existing payout safety tests also cover default-disabled payouts, wrong-PIN rejection before payout creation, beneficiary encryption, and retaining beneficiaries referenced by payout history.
- Existing bulk batch feature tests already cover same-key/same-payload idempotent retries without a second queue dispatch, conflicting payload rejection, same batch key across different users, owner-only recovery/status access, and worker handling of unknown provider outcomes without resubmission.

### Verification status for this sweep

- New/extended tests committed to main: yes.
- Laravel feature tests executed after these changes: no. Current GitHub integration reports no status checks or usable workflow runs for the commits, and the execution environment cannot fetch the repository checkout. These are added regression assertions, not verified passing results.
- Still outstanding: payout-service duplicate-request/provider-submit-once test with a mocked provider; actual concurrent database tests for wallet transfers, payouts and batch-key creation; direct payout-record authorization/ownership test if a detail endpoint is introduced; full suite and frontend build.
- No real provider requests or production financial transactions were sent. Keep live payouts and purchases gated until the relevant tests run successfully in the project test environment.


### Payout idempotency regression coverage — 2026-10-20

- Added `test_repeated_payout_request_debits_once_and_submits_to_provider_once` to `tests/Feature/BankPayoutSafetyTest.php` using a mocked Paystack client. It expects two identical calls to return the same payout, retain one payout record, debit the wallet only once, and call the provider transfer method exactly once.
- Added `test_payout_idempotency_key_cannot_be_reused_for_different_amount` to prove a reused key with a changed amount raises `IdempotencyConflict` and does not permit another provider submission.
- Corrected escaped fully-qualified class names in the payout-history ownership test in the same file.
- Commit: `360f14393f80daab83a324c0882f9ede8375540f` on `main`. The GitHub status endpoint currently reports no status checks for this commit; this is not a test pass.
- **Concurrency coverage remains open.** These are deterministic sequential idempotency regressions, not simultaneous multi-connection tests. The configured PHPUnit suite uses in-memory SQLite, which cannot establish production-like row-lock behavior. Add/run a disposable MariaDB integration test with independent connections/processes before claiming concurrent duplicate payout safety.
- **Laravel test execution remains blocked/unverified in this environment.** No test command was executed against an installed checkout, and no CI run/result is available for the latest commit. Do not advance to the next security area or enable payouts/live financial operations until targeted tests and the full suite run successfully, including MariaDB concurrency coverage.


## 2026-10-20 payout concurrency execution gate

- Hardened `BankPayoutService::requestPayout` to re-check the user's idempotency key after acquiring the user's wallet row lock. This closes the stale-read race where two independent requests could both pass the first lookup before contending on the wallet lock; the second request now reuses the committed payout or raises an idempotency conflict before any debit.
- Added `tests/Feature/BankPayoutMariaDbConcurrencyTest.php` plus `tests/Support/bank_payout_concurrency_worker.php`. The test requires a dedicated MariaDB database ending in `_test`, starts two independent PHP processes/connections, and asserts one payout row, one debit, one provider submission, and the expected final balance.
- Added payout, peer-transfer, bulk-recovery, and MariaDB payout-race test files to `.github/workflows/laravel.yml` before the full Laravel suite.
- Verification completed successfully on commit `e92ba273dbc0d4b253c4a7857c8067d4010cf353`:
  - Phase 1 security workflow: SQLite full suite passed (567 passed, 24 skipped; 4,016 assertions); MariaDB focused payout/peer-transfer/bulk-recovery/payout-race suite passed (33 passed; 167 assertions).
  - Laravel and frontend workflow: PHP 8.3 full suite passed (591 passed; 4,237 assertions); PHP 8.4 full suite passed (591 passed; 4,237 assertions); `npm run build` passed.
  - Build-and-publish frontend workflow completed successfully.
- Actual workflow run links: [Phase 1 security verification](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39494518297), [Laravel and frontend checks](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39494518447), and [frontend assets build](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39494518346).
- The independent-process payout regression passed on MariaDB and asserts one payout row, one debit, one provider submission, and the expected final wallet balance. No live payout, purchase, or other real financial transaction was initiated.
