# SEMIZZY ONE — Phase 1 financial-mutation and authorization audit checkpoint

**Review date:** 2026-10-22  
**Branch policy:** `main` only  
**Repository head at checkpoint:** `90bcaf45266419d815b9ce4d4e231788cd4cc2c2`  
**Latest tested runtime-code SHA:** `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`  
**Status:** Source review progressed; the blueprint-wide route/role verification is not yet complete. This document is not a penetration-test report or production sign-off.

## 1. Financial mutation source review

Reviewed the customer financial route group in `routes/web.php`, webhook routes in `routes/api.php`, and the implementation/test evidence for funding intents and gateway settlement, manual deposits, refunds, P2P transfers, bank beneficiaries/payouts, virtual accounts, scheduled purchases, service beneficiaries, and VTU order/bulk-order submission and recovery.

| Mutation surface | Source-level control observed | Existing regression evidence | Remaining limitation |
|---|---|---|---|
| Funding intents / payment return / gateway callbacks | Funding intent belongs to authenticated user's wallet; browser return is not the settlement authority; webhook path verifies provider transaction details before settlement | `PaymentFundingWorkflowTest`, `PaymentFundingMariaDbConcurrencyTest`, `PaymentIntentIdempotencyTest` | Verify provider-specific live contract and production keys only during authorised onboarding |
| Manual deposits | Submission service checks wallet ownership/currency, validates evidence fingerprint, flags duplicate evidence; submission does not credit wallet; staff approval is separate | `ManualDepositWorkflowTest`, manual-deposit concurrency/review coverage | Production private storage, DB constraints/migrations and cPanel filesystem permissions need deployment verification |
| Refund requests / approval | Request scopes intent through the caller's wallet; request is not settlement; approval blocks self-approval, locks refund/intent, reserves funds through ledger, and treats uncertain provider outcome as reconciliation-required | `PaymentRefundWorkflowTest`, `PaymentRefundReconciliationTest`, `PaymentRefundMariaDbConcurrencyTest` | Finish route-by-route role/HTTP-method matrix; never certify live refund behavior from fakes |
| P2P transfers | Controller verifies transaction PIN; service rejects self-transfer, hashes payload, locks both wallets in deterministic order, rechecks idempotency, posts paired ledger entries in one transaction, and resolves unique-key replay/conflict | `PeerWalletTransferTest`, `PeerWalletTransferMariaDbConcurrencyTest` | Expand the all-role and cross-account test matrix; live money transfer remains prohibited in CI |
| Bank beneficiaries / payouts | Beneficiary claims use a keyed account fingerprint and unique DB claim; beneficiary/history reads are user-scoped; payout checks PIN, verified owned beneficiary, configured tier policy and idempotency before reservation; ambiguous provider outcomes are quarantined | `BankPayoutSafetyTest`, `BankPayoutMariaDbConcurrencyTest` | Confirm provider contract and reconciliation process in sandbox/approved deployment; verify DB schema on target host |
| Virtual accounts | Account display/provisioning is user-scoped and disabled by default; settlement is intended to require signed webhook plus server-side provider verification and ledger idempotency | `PaymentFundingWorkflowTest` | Paystack-only implementation; requested multi-provider/multiple-account capability remains partial and disabled pending acceptance |
| Scheduled purchases | List/delete are scoped to authenticated user; creation uses PIN, supported service/product checks, user lock and idempotency payload hash; processing schedules cannot be falsely cancelled | `ScheduledPurchaseRunnerTest`, `FinancialMutationAuthenticationTest` | Confirm every failure/late-response branch and cross-process scheduler recovery in the remaining Action 1/8 sweep |
| VTU single/bulk purchase and recovery | User-scoped order/batch lookups; durable idempotency; same-key bulk creation has DB uniqueness recovery; independent workers test duplicate item submission and crash-after-acceptance quarantine | `VtuOrderMariaDbConcurrencyTest`, `VtuProviderWebhookRecoveryTest`, `FinancialMutationAuthenticationTest` | Provider contract/requery semantics remain provider-specific gates; do not resubmit ambiguous provider outcomes |
| Service beneficiaries | Queries and delete are scoped to authenticated user; reusable service details are allowlisted to exclude PIN/payment data | Service-beneficiary feature coverage | Explicitly add/confirm cross-user ID substitution and concurrent duplicate behavior in the final endpoint matrix |

No new production-code defect was confirmed by this focused source pass, so no speculative financial code change was made. That is a bounded finding, not proof of absence of defects.

## 2. Authorization evidence and gaps

Confirmed in source:
- Financial customer routes are in authenticated groups or individually use `auth`; mutation endpoints have throttles where reviewed.
- Refund approval has its own `can:refunds.approve` permission, separate from general staff management.
- Sensitive bank payout and P2P transfer handlers verify the transaction PIN before invoking the service.
- Customer refund detail, payout history, transfer history, scheduled purchase list/delete, service beneficiaries, session revocation and VTU order/batch recovery use caller-scoped queries or service ownership checks.
- Admin routes are grouped behind `auth` plus `can:staff.permissions.manage` or a more specific capability; direct-URL tests explicitly cover representative ADMIN, STAFF, SUPPORT and USER cases.

Existing test coverage:
- `FinancialMutationAuthenticationTest` checks guests against a set of financial mutation and recovery routes.
- `AdminDirectUrlAuthorizationTest` checks guest redirects, representative role/capability access to dashboard/refund pages, and suspended-staff logout.
- `PaymentRefundWorkflowTest` checks a customer cannot approve a refund and verifies authorized approval/ledger behavior.
- `BankPayoutSafetyTest` checks wrong-PIN denial before payout creation and safe defaults.
- The financial mutation matrix is maintained at `docs/finance/financial-mutation-authorization-matrix.md`.

Still open; do not infer completion from route middleware presence:
- Generate a complete named-route/method inventory and test guest, USER, SUPPORT, STAFF and ADMIN against every sensitive GET/POST/PUT/PATCH/DELETE action.
- Add/confirm cross-user resource substitution cases for deposit evidence/review, KYC documents, support tickets, beneficiary IDs, scheduled purchase IDs, sessions, payout/refund IDs, passkeys and workspace membership/invitations.
- Complete staff permission grant/revoke escalation tests, including self-grants, protected ADMIN capability assignments, and immediate revocation/downgrade.
- Verify all authentication success paths rotate session IDs and account suspension is enforced consistently.
- Complete end-to-end WebAuthn registration/assertion challenge expiry/single-use, RP-ID/origin, signature, credential binding, replay, rate-limit and session-rotation acceptance.
- Verify CSRF/cookie/HTTPS behavior in the deployed environment; repository source cannot establish the live web PHP handler or cookie settings.

These items map to AUTH-01 through AUTH-10 in the source authentication/authorization audit checkpoint at commit `90bcaf45266419d815b9ce4d4e231788cd4cc2c2`. They remain open until tests/evidence are attached.

## 3. Repository hygiene / reproducibility findings to reconcile

- The accidentally tracked root log `laravel (8).log` has been removed from the current tree (commit `b55f6b467fdca296e68159e0390c1b6e947a8fa2`) and its exact name added to `.gitignore` (commit `cc74bd1743594f21b086ba4638d05e18a43b3f93`). This does **not** erase historical Git objects, clones, caches or previously downloaded copies. History/content exposure review and credential rotation remain required if any sensitive value is found.
- Root `composer.lock` and `package-lock.json` were not found in the repository checks performed for the security findings register. CI uses non-lockfile `composer install` / `npm install` according to that register. Dependency reproducibility and vulnerability scanning remain open; do not update dependency versions blindly.
- The earlier cPanel PHP report was 8.4.22. Actual live web-handler/CLI/cron versions and vendor security backports are not proven by repository CI; hosting confirmation remains mandatory.

## 4. CI evidence and release status

The completion register records these workflows as successful for runtime-code SHA `bb558be45160d6794d1d469355bf2b3798869067`:
- [Laravel/frontend full suite, PHP 8.3 and 8.4](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39732897103)
- [SQLite and MariaDB security verification](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39732897104)
- [Frontend asset build/publication](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39732897118)

Subsequent commits up to this checkpoint include documentation updates and the tracked-log removal/ignore change; the current main head is not being represented as a runtime-code SHA tested by those links. If executable code changes during the remaining audit, rerun the affected full PHP 8.3/8.4, SQLite/MariaDB security, frontend build and asset-publication workflows on the exact new code SHA and inspect terminal conclusions.

## 5. Explicit deployment gates — not passed

- Live provider contracts, sandbox transaction/requery behavior and production account/catalogue exports.
- Real-device, authorised SMS/WhatsApp delivery and vendor failure/retry behavior.
- HTTPS browser passkey registration/login/recovery ceremonies.
- cPanel live PHP runtime and security patch level, environment/secure cookies, filesystem permissions, migration/schema reconciliation, cron/queue operation, worker restart/log checks and first-admin access.
- Any real-money payment, refund, payout, transfer or provider purchase.

No real financial/provider transaction was initiated for this audit. Do not open feature gates or mark the deployment-only items as passed.

## Decision

**Phase 1 remains OPEN.** The focused financial mutation source review and existing test inventory are documented. Full authorization route/method/role coverage, cross-user resource tests, security findings reconciliation and exact-current-code CI evidence remain to be closed before repository sign-off. Deployment-only gates stay separately pending.
