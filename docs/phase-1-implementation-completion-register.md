# SEMIZZY ONE — Phase 1 Implementation Completion Register

This register tracks implementation against `docs/SEMIZZY-BLUEPRINT.md` and the owner-approved core requirements. It is not a test report. **No item is accepted solely because a file or route exists**; final acceptance happens after implementation is complete and the planned test sweep is run.

## Current verification update — 2026-10-22

Latest **runtime-code** commit verified by terminal CI: `bb558be45160d6794d1d469355bf2b3798869067` on `main`. The subsequent commit `0e239f24227d9acd1a023b43b4edd49e63fef7bd` adds only the financial mutation authorization/ownership matrix documentation; it does not change executable code.

- [Laravel and frontend checks](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39732897103) completed **successfully** on `bb558be45160d6794d1d469355bf2b3798869067`: PHP 8.3 full Laravel suite passed; PHP 8.4 full Laravel suite passed; frontend build passed; both MariaDB error-123 diagnostic jobs passed.
- [Phase 1 security verification](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39732897104) completed **successfully** on the same commit: SQLite and MariaDB suite jobs passed.
- [Frontend asset build/publication](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39732897118) completed **successfully** on the same commit.
- Added `docs/finance/financial-mutation-authorization-matrix.md` at `0e239f24227d9acd1a023b43b4edd49e63fef7bd`, mapping customer financial mutations and staff/callback mutations to route guards, ownership checks, service invariants and existing regression suites. This is a source-level audit artifact, not a live-provider certification.
- **Bulk Action 3 financial audit:** reviewed funding intents/webhook settlement, manual deposits, refunds, P2P transfers, bank beneficiaries/payouts, virtual-account provisioning, scheduled purchases, VTU purchase/recovery, and related route/controller/service boundaries. Existing code and tests cover owner-scoped reads, transaction PIN/lockout on sensitive transfers/payouts, self-review/self-approval guards, idempotency/payload conflict, wallet and record locks, ledger settlement, and quarantine/reconciliation for uncertain provider outcomes. No additional production-code defect was confirmed in this source-only pass; no speculative financial behavior change was made.
- The full suites and focused security workflow are green on `bb558be...`; the matrix documentation commit and this register update are documentation-only. The workflows for the final register-update commit must still be checked for terminal status before claiming CI green on that exact SHA.
- No real payment, refund, payout, or provider purchase was initiated. Live provider contracts, production cPanel settings, real-device SMS/WhatsApp delivery and browser/WebAuthn ceremony checks on the production HTTPS origin remain separate release gates.

**Phase 1 status: OPEN.** Bulk Action 3 source-level route-to-service matrix is documented, but Phase 1 is not signed off until the final register commit's CI is terminal-green and all deployment-only gates are separately verified.

## Bulk Action 7 verification update — 2026-10-22

Latest implementation commit under verification: `284dca89dc7161bdd2693773ad7b656e29aadff2` on `main`. The current Laravel/frontend and security workflows are queued or running; do not treat the new changes as CI-accepted until those runs complete successfully.

Previous runtime-code baseline `3095bdada1469dd6229acaee5cec65be2590a37b` passed both PHP 8.3/8.4 full suites, frontend build, SQLite security suite, MariaDB security suite, and MariaDB error-123 diagnostics:
- [Laravel and frontend checks](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39737423498)
- [Phase 1 security verification](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39737423625)
- [Frontend asset build/publication](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39737423484)

Changes made since that baseline:
- Corrected accepted-order retry regression remains in `tests/Feature/VtuOrderWorkflowTest.php`; the previously green baseline included this test.
- `PeerWalletTransferService` and `BankPayoutService` now apply the configured `UserLevelPolicyService` checks inside their wallet-locking transaction after idempotency replay checks and before any debit/reservation. Added feature tests for peer-transfer daily-debit limits and payout single-transaction limits. These limits use existing DB-configured policies; no limit values were invented.
- `VtuOrderService` now calls `AddonLifecyclePolicy` for products explicitly mapped with metadata `addon_key`. A source allowlist maps known service add-on keys to the reviewed VTU implementation and service types; missing/disabled/incompatible/unregistered mappings fail closed for new purchases. Existing-order lookup remains before admission, preserving same-key accepted-order recovery behavior.
- Expanded `DashboardLinkRouteMatrixTest` to cover account tiers, transaction PIN, two-factor, passkeys, privacy requests, virtual accounts, bank transfers, transfer history and scheduled purchases. Existing catalogue tests already cover provider metadata privacy, opaque selection codes, duplicate-provider options and import preservation.
- One intermediate run failed PHP parsing in the newly added tier-policy test; the namespace references were corrected in the subsequent commit. The corrected file must be verified by the latest full suites before acceptance.

Remaining acceptance gaps:
- `AddonLifecyclePolicy::mayContinueAcceptedOperation` still needs a confirmed runtime recovery-path call site; the new integration covers admission for explicitly mapped new purchases, not the full add-on lifecycle.
- Dashboard route tests establish registered routes and middleware/navigation markers, not full browser-by-browser blueprint parity across every user/admin page. Catalogue acceptance still needs a service-by-service provider-readiness review; unverified categories must remain preview/gated.
- Confirm the latest workflows pass on the closing code commit and rerun any failed job after fixes. Keep the frozen theme unchanged.

**Bulk Action 7 status: OPEN.** The tier checks, mapped-add-on admission and expanded route assertions are committed, but the latest CI is not yet terminal. Phase 1 remains OPEN. No live purchases or real financial transactions were triggered; production provider contracts, real-device SMS/WhatsApp delivery, HTTPS browser passkey ceremony and cPanel-only checks remain release/deployment gates.

## Bulk Action 1 — critical security and authentication sweep (2026-10-21)

**Implementation and automated regression coverage are in place; this is not full Phase 1 sign-off.** Current code covers verified mobile-number login, verified WhatsApp login, hashed/expiring/single-use OTP challenges, atomic TOTP recovery-code management, five-minute TOTP challenge expiry, password-change/reset invalidation of pending sign-in challenges, session revocation, transaction-PIN controls, passkey origin/confirmation guards, and passkey authorization that blocks inactive accounts and configured OTP/TOTP step-up bypasses.

Latest evidence:
- [Phase 1 security verification](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39550576102): SQLite full suite **587 passed, 25 skipped (4,189 assertions)**; focused MariaDB suite **103 passed (671 assertions)**.
- [PHP 8.3/8.4 and frontend checks](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39550284048): each PHP version full suite **612 passed (4,421 assertions)**; frontend build passed.
- These checks do not replace real-browser passkey ceremony acceptance or real SMS/WhatsApp provider onboarding. Keep PASSKEYS_ENABLED=false and SMS/WhatsApp delivery disabled until their own production gates pass.

## Capability inventory

| Capability | Code presence | Completion note |
|---|---|---|
| Manual bank deposits | Present | Must pass full acceptance and deployment schema checks; evidence uploads must never credit funds directly. |
| Wallet ledger, funding intents, Paystack webhooks/refunds | Present | Keep live settlement gated until release criteria are satisfied. |
| P2P wallet transfer | Present | Full regression/concurrency acceptance remains part of the final test sweep. |
| Bank beneficiaries, directory and payout reconciliation | Present | External payout remains gated by provider readiness and verified account resolution. |
| Account type, transaction level policies and staff permissions | Present | Keep account type separate from transaction level; enforce all boundaries server-side. |
| PIN security and user sessions | Core regression coverage passing; broader wallet endpoint audit remains open | Password changes re-check the current password under a user-row lock, revoke other sessions, and invalidate pending OTP challenges; password reset clears the transaction PIN, revokes sessions, and invalidates pending OTP challenges. PIN/wallet controls still require the separate endpoint-by-endpoint audit in Bulk Action 2. |
| Verified mobile-number sign-in and ownership verification | Implemented; automated regression coverage passing | SMS verification codes are hashed, expiring, single-use and attempt-limited; unique database claims prevent the same verified number being assigned to multiple accounts. Changing the number clears verification and outstanding challenges. SMS provider onboarding remains gated. |
| Referrals | Present | Rewards must remain ledger-backed and subject to configured qualifying rules. |
| Saved service beneficiaries | Present | Final ownership and compatibility checks are part of acceptance. |
| Scheduled service purchases | Present | Only supported handlers may be schedulable; unknown outcomes must pause, not replay. |
| Bulk VTU purchases | Present | Per-item idempotency and unknown-outcome recovery require final acceptance. |
| VTU biller verification | Partial | Registry currently has a VTpass adapter; add other adapters only against real, reviewed provider contracts. |
| Customer/admin dashboards, platform settings, feature controls | Present | Audit each action against the blueprint; menu visibility alone is not completion. |
| Icon-led service catalogue | Present | Preserve the existing theme; service availability must reflect actual implementation and release gates. |
| Streaming and education catalogues | Present | Purchases remain gated until each provider fulfilment/recovery path is verified. |
| Dedicated virtual accounts | Partial implementation added | Paystack account storage, provisioning page/service, and verified charge-success settlement path are now in code. Provisioning is off by default pending tests and provider onboarding. Multi-provider/multi-account coverage is not complete and must not be claimed. |
| Add-on registry and dependency/compatibility management | Implemented in code; acceptance pending | Core-owned manifest registry, minimum-core compatibility checks, dependency enable/disable ordering, cycle rejection, admin audit events, and admin UI added. Registry state does not execute or install code. Acceptance tests and migration/deployment checks remain pending. See `docs/platform/addon-registry.md`. |
| Privacy/data-request, retention and incident workflows | Implemented in code; acceptance pending | Customer privacy request intake/status, basic private account-data export, staff-only request review, audited retention-policy configuration with automatic deletion explicitly disabled, and a security incident lifecycle with event history/audit records are added. Tests and full export/retention/legal acceptance remain pending. See `docs/platform/privacy-retention-incidents.md`. |
| Authenticator-app TOTP 2FA | Implemented; automated regression coverage passing | Encrypted secret; atomic enable/disable and recovery-code replacement; ten hashed single-use recovery codes; five-minute challenge expiry; password-fingerprint invalidation after credential changes; five-attempt challenge limit; email-OTP chaining. SQLite full-suite and focused MariaDB tests pass. Production migration/configuration still needs deployment verification. |
| Email/SMS/WhatsApp login OTP | Core code and automated tests passing; vendor onboarding pending | Hashed, expiring, single-use challenges with bounded attempts; email OTP can chain into TOTP. SMS login delivery requires a verified mobile-number claim; mobile verification uses an expiring hashed code and a unique claim table. SMS/WhatsApp use a generic HTTPS JSON adapter and remain disabled until a real vendor contract and handset delivery are verified. See docs/security/login-otp-delivery.md. |
| Biometric/passkey login | Foundation and authorization guards implemented; real-browser acceptance pending | Laravel WebAuthn server package and browser client, additive credential migration, authenticated management UI, five-minute password confirmation, exact HTTPS origin checks, wildcard-origin rejection, inactive-account rejection and OTP/TOTP step-up blocking are in code. Automated origin/confirmation tests pass. Keep disabled until the actual registration/assertion/recovery ceremony is verified in a production-like HTTPS browser environment. |
| Owner-requested service categories beyond current catalogue (government document registration, number verification, SMM, social accounts and betting platforms) | Needs blueprint-scope audit | Add real provider-backed flows where approved; do not expose fake purchases or pretend unsupported providers are live. |

| Remaining blueprint gap audit | Open; no sign-off | Current prioritized gap list and recommended implementation order: `docs/phase-1-remaining-blueprint-gap-audit.md`. |

## Virtual-account release conditions

- Provider-issued account state must be persisted and recoverable after ambiguous API timeouts.
- Explicit customer consent is required before sending identity/contact details to the provider.
- A deposit can settle only after a signed webhook and server-side provider verification agree on transaction ID, amount, currency, dedicated-NUBAN channel, receiver account number and customer assignment.
- The wallet credit uses the existing ledger, a stable provider transaction idempotency key and a unique deposit record in one database transaction.
- Test-mode deposits never create spendable wallet funds.
- `SEMIZZY_VIRTUAL_ACCOUNTS_ENABLED` defaults to false. Keep it false until the complete test sweep passes and provider onboarding/reconciliation are confirmed.
- The current adapter is Paystack-only. Do not represent the requested multi-provider/multiple-account requirement as complete until additional supported provider adapters and their settlement paths exist.

## Change-control rules

- Canonical repository: `Emmizzy01/semizzy-vtu`; `main` only.
- Do not modify or redesign the theme during this sweep.
- Do not reset production data, run destructive production commands, or perform real payments, payouts, refunds, or provider purchases.
- Treat production schema, first-admin access, scheduler, queue, and provider onboarding as deployment verification where appropriate; continue unrelated code implementation without waiting for live-server access.


## Bulk Action — webhook replay and recovery permission isolation (2026-10-21)

This checkpoint adds focused regression coverage; it does **not** close Phase 1.

- Commit `c54d052d75ebfdac444fae1aa8c3075ec226d70b` adds a payment webhook test proving reuse of one provider event ID with a changed payload is rejected without a second wallet credit or duplicate ledger/event records. Its Laravel/frontend workflow completed successfully: PHP 8.3 and PHP 8.4 suites passed, and the frontend build passed. [Workflow evidence](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39628126277).
- Commit `4821859bd99c6dead8a61c6970229ede5d3abafb` strengthens `AdminVtuOrderOperationsTest`: a customer and an account with only `refunds.approve` are both explicitly denied the administrative VTU queue and reconcile POST. The dedicated SQLite security suite and full PHP suites for this commit are still running at the latest check; do not claim this newest regression has passed yet.
- For `4821859`, the frontend build step has passed. PHP 8.3, PHP 8.4, the MariaDB security suite, and asset publication were still in progress. [Laravel/frontend workflow](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39628664526), [security workflow](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39628664542), [asset build workflow](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39628664580).
- Dispatch/source review recorded in `docs/platform/addon-lifecycle-contract.md` identifies the bulk-purchase job and scheduled recovery paths, distinguishes synchronous OTP/invitation delivery from queued jobs, and keeps lifecycle policy unwired until full route/job authorization and recovery acceptance is complete.

### Phase 1 release decision

**Phase 1 remains OPEN.** Latest inspected commit `0b6dca458a461785de666f2234d78ae66f609c0a` has green CI: [Phase 1 security verification](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39728852170), [Laravel and frontend checks](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39728852180), and [public-assets build](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39728852141). Outstanding gates include endpoint-by-endpoint wallet/PIN/authorization and recovery acceptance; real-browser HTTPS passkey ceremonies; vendor-specific SMS/WhatsApp contract and authorized device delivery; multi-provider virtual-account coverage; live provider contract verification; and production deployment/schema/scheduler/queue/first-admin checks. These are not safe to waive to make a phase appear complete.

No production database changes, real payments/refunds/payouts, live provider purchases, or feature-gate openings were performed by this bulk action. Continue on `main` only, preserve the existing UI/routes/contracts, and do not wire addon lifecycle admission until its route and background recovery gates are verified.

## One-sweep master acceptance matrix — 2026-10-22

This matrix coordinates the eight bulk actions requested for the Phase 1 completion sweep. It is a live checklist, not a declaration of completion. Each action remains unsigned until its repository-verifiable acceptance criteria have passed and evidence is recorded.

| Bulk action | Scope | Current state | Acceptance still required |
|---|---|---|---|
| 1. Transaction recovery and crash safety | Payments, refunds, payouts, VTU orders, scheduled purchases, bulk purchases, duplicate workers and late responses | **In progress** — scheduled-runner recovery now preserves an operator/recovery pause reason when a late provider response returns; regression coverage updated | Confirm the latest PHP 8.3/8.4 and MariaDB/SQLite checks; finish the remaining cross-workflow crash/recovery audit and concurrency evidence |
| 2. Provider integration and failover | VTpass, Sogo, GSUBZ, Dataway, lookup contracts, status normalization and safe retry | **Open** — mock/adapter coverage exists; unverified contracts remain gated | Complete code-level contract review and sandbox/mock regression checks; document provider-specific external evidence; keep unsafe failover and purchases disabled |
| 3. Wallet and payment integrity | Ledger, deposits, gateway callbacks, idempotency, refunds, reversals and reconciliation | **Open** — substantial workflow and concurrency tests exist | Close the endpoint-by-endpoint financial mutation audit and verify exactly-once settlement, changed-payload conflicts, balanced ledger entries and uncertain-outcome handling |
| 4. Payouts and transfers | Bank beneficiaries, payout lifecycle, P2P transfers, payout races and reconciliation | **Open** — payout idempotency, unknown-outcome and concurrency regressions exist | Verify the complete payout/transfer matrix and provider reconciliation requirements; do not send live money during CI |
| 5. Authentication and account security | OTP, PIN, TOTP/2FA, passkeys, sessions, account recovery and lockouts | **Partially verified** — automated security workflow passed on commit `228b36112914386b18e0d778d2b34113c467a64d` | Complete any remaining repository-verifiable gaps; real-browser HTTPS passkey ceremony and authorised SMS/WhatsApp vendor/device verification remain deployment/activation gates |
| 6. Roles, permissions and admin security | Server-side role/action matrix, staff permissions, ownership, sensitive actions and audit records | **Open** — targeted route and permission regression tests exist | Complete the blueprint-wide role/action matrix, including cross-tenant access, privilege escalation and separation of financial duties |
| 7. Core platform and blueprint compliance | Migrations/schema drift, account levels, add-ons, privacy, retention, incident workflows, catalogues and requested services | **Closed for repository-verifiable acceptance on `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`** | Automated PHP 8.3/8.4 full suites, SQLite and focused MariaDB security suites, frontend build, both MariaDB error-123 modes, authenticated workspace-page render checks, and existing catalogue/navigation regressions passed. HTTPS browser/WebAuthn, authorised SMS/WhatsApp delivery, provider contracts and cPanel checks remain deployment/activation gates. |
| 8. Final integration and acceptance | Full test suites, MariaDB/SQLite concurrency, frontend build, security checks and release evidence | **In progress on `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`** — latest PHP 8.3/8.4 suites, SQLite/MariaDB security suites, frontend build and MariaDB error-123 diagnostics all passed | Latest diff reviewed: the MariaDB concurrency suite already contains independent-process same-bulk-key submission, duplicate bulk-job delivery, and simulated worker-crash-after-provider-acceptance tests. Those documented gaps are closed by passing focused MariaDB CI on code SHA `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`. Continue the endpoint-by-endpoint financial mutation and authorization audit; record any remaining gaps and rerun CI for code changes without waiving deployment-only gates |

### Deployment checklist — intentionally deferred until deployment

- Verify cPanel/PHP runtime, environment configuration, migrations, permissions, scheduler/queue operation, assets, logs and production smoke checks.
- Verify real-browser HTTPS passkey registration/login and authorised SMS/WhatsApp delivery.
- Complete provider-account and provider-contract checks that require authorised external access.
- Keep provider purchases, payouts, refunds and virtual-account provisioning disabled until their individual activation gates are met.

**Sign-off rule:** a deployment-only check must be labelled “deferred to deployment / not yet run,” not falsely marked passed and not used to block unrelated repository implementation. No bulk action is signed off by this matrix alone.



## Bulk Action 7 execution update — 2026-10-22 (Core recovery integration)

- Confirmed the previously pending CI runs on documentation commit `d5d805819315c4d09eef28fa9fcc938204ad32bb`: [Laravel/frontend](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39739641037) passed PHP 8.3 and PHP 8.4 full Laravel suites, frontend build, and both MariaDB error-123 diagnostic jobs; [security verification](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39739641222) passed SQLite and focused MariaDB security suites. These runs verify the code present at that commit, before the recovery integration below.
- Added a Core-owned runtime call to `AddonLifecyclePolicy::mayContinueAcceptedOperation` at the start of `VtuOrderService::reconcile`. Recovery is permitted only for a processing/awaiting-reconciliation order with its durable dispatch marker and wallet transaction reference; terminal orders are rejected. This gate is separate from new-purchase/add-on admission and does not itself authorize provider submission.
- Added feature regressions for (a) reconciling an accepted pending order after the new-purchase feature is disabled, with one provider submission, one lookup, one order and no additional wallet debit, and (b) terminal successful orders not resuming provider lookup.
- New implementation/test commit is awaiting its own CI results. Dashboard and catalogue acceptance is still incomplete: route matrices and selected page/controller feature tests exist, but a full page-by-page browser acceptance record and complete service-by-service provider readiness proof are not yet recorded. Frozen theme unchanged; no live financial/provider purchase initiated.
- Remaining release-only gates include production provider contract verification, authorized real-device SMS/WhatsApp delivery, HTTPS browser/WebAuthn ceremony, and cPanel production scheduler/log/environment checks.

**Bulk Action 7 status: OPEN.** The recovery integration and new regressions are implemented, but acceptance depends on green CI on the new code SHA and completion of the dashboard/navigation and service-catalogue acceptance matrix. Phase 1 remains OPEN.


## Bulk Action 7 acceptance expansion — 2026-10-22

- Added `DashboardRoutingTest::test_authenticated_customer_workspace_pages_render_without_route_only_shortcuts`, which makes authenticated GET requests and requires successful rendering for profile, privacy requests, tier page, transaction PIN, two-factor, passkeys, wallet transfer, virtual accounts, transfer history, bank payout/history, scheduled purchases and service beneficiaries. Existing dashboard tests separately verify customer dashboard Inertia data/ownership and distinct airtime/data/electricity/TV purchase pages.
- The catalogue regression suite continues to verify that provider-owned identifiers/cost/routing fields are not returned to customers, opaque selection codes are used, and catalogue refresh preserves operator price/status/custom metadata. Import tests also require unapproved imported products to remain inactive. This is automated feature acceptance, not proof that every provider contract is production-ready.
- The current runtime recovery implementation and these added page-render assertions require fresh PHP 8.3/8.4, focused MariaDB security and asset workflow results on the exact resulting commit. Any failure must be fixed and the affected workflow rerun before acceptance.
- Full real-browser navigation/accessibility/responsive acceptance is still not certified by HTTP feature tests; production provider contracts, SMS/WhatsApp device delivery, HTTPS WebAuthn ceremony and cPanel runtime checks remain release gates. Frozen theme unchanged.

**Bulk Action 7 status: OPEN pending terminal CI on the exact implementation SHA and any failures discovered by the new page-render suite.**


## CI failure triage — 2026-10-22

- The first asset workflow that also runs the Laravel suite exposed a test assertion API misuse in the new Core recovery regression: a human-readable message was passed as Laravel's third `assertDatabaseCount` argument, which is the database connection name. This was a test-only defect, not an application runtime failure.
- Corrected the assertion to use the standard signature and compare the wallet balance captured immediately before reconciliation. The failed run is [asset build/test run 39741116648](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39741116648); it is not accepted as green.
- The fix requires a fresh full-suite run on the resulting main commit. Action 7 remains OPEN until PHP 8.3/8.4, MariaDB security, asset build and new workspace-page smoke tests pass on the same code SHA.


## Bulk Action 7 closure and Bulk Action 8 start — 2026-10-22

**Bulk Action 7: CLOSED for repository-verifiable acceptance** on code SHA `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`.

Acceptance evidence on that exact code SHA:
- [Laravel/frontend workflow 39742792095](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792095): PHP 8.3 full Laravel suite passed; PHP 8.4 full Laravel suite passed; frontend build passed; MariaDB error-123 diagnostics passed in default and OFF modes.
- [Phase 1 security workflow 39742792012](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792012): SQLite suite passed and focused MariaDB security suite passed.
- [Public-assets build 39742792188](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792188): passed.
- Dashboard workspace page-render acceptance now includes customer workspace pages and authorized admin pages, including the corrected refund-review permission setup. Existing catalogue tests cover customer-facing provider metadata privacy, opaque selection, import/refresh preservation of admin pricing and approval, and route/navigation mappings.
- Core accepted-order reconciliation recovery integration and terminal-order non-resumption regressions are included in the tested SHA.

Closure scope is intentionally limited to repository-verifiable checks. Full real-browser visual/accessibility/responsive review, HTTPS WebAuthn ceremonies, authorised SMS/WhatsApp device delivery, live provider contracts and cPanel production runtime checks are explicitly deferred to deployment/activation and are not claimed as passed. No live payment, payout, refund, or provider purchase was triggered; no feature gates were opened.

**Bulk Action 8: IN PROGRESS.** Continue final integration acceptance: inspect the latest diff and evidence register, close any remaining repository-verifiable endpoint authorization/financial mutation gaps and documented bulk-key independent-process/worker-interruption coverage gaps, rerun affected workflows on any new code SHA, then record final sign-off. Phase 1 remains OPEN until the full eight-action release criteria are met.


## Bulk Action 8 — first integration audit update — 2026-10-22

- Reviewed the exact code diff at `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`; the only code change in that final patch is the explicit `refunds.approve` permission grant required by the authorized admin page-render acceptance test.
- Rechecked the independent-process MariaDB harness and test coverage. `VtuOrderMariaDbConcurrencyTest` includes (1) two simultaneous independent requests using the same bulk batch key, asserting one batch/ten items and one accepted plus one replay response; (2) duplicate independent job deliveries asserting a single item submission; and (3) a worker terminated immediately after simulated provider acceptance, asserting stale recovery quarantines the item as unknown and does not resubmit. The focused MariaDB security workflow passed these tests on the current code SHA.
- Therefore the earlier register language describing the bulk-key race and worker-interruption coverage as still missing is superseded by this verified coverage. This does not replace a full endpoint-by-endpoint review of every financial mutation, authorization boundary, and recovery state.
- Evidence: [Laravel/frontend full suites on PHP 8.3 and 8.4](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792095), [SQLite and focused MariaDB security suites](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792012), and [public-assets build](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792188) all passed for code SHA `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`.
- Remaining Action 8 work: complete and record the broad financial-mutation/authorization audit, inspect all outstanding action registers for stale or conflicting claims, and decide final Phase 1 status only from recorded evidence. Deployment-only browser/provider/cPanel gates remain deferred and explicitly unpassed.

**Bulk Action 8 status: IN PROGRESS. Phase 1 remains OPEN.**


## Authoritative reconciliation — financial mutation, authorization and security findings — 2026-10-22

This section supersedes earlier historical status paragraphs where they conflict with later evidence. Historical entries are retained for audit traceability and must not be read as the current state when contradicted by this reconciliation.

- Current repository head before this register update: `2cf320ae87bf41c2ee7dbca24973ffb9f4b0b6da` (audit documentation commit). The latest runtime-code SHA with terminal green full-suite/security/build evidence recorded above is `bb558be45160d6794d1d469355bf2b3798869067`; the later main commits include documentation and removal/ignore of a tracked application log, and are not represented as an executable-code SHA tested by those workflow links.
- Detailed source review and remaining findings are now recorded in [Phase 1 financial-mutation and authorization audit](docs/security/phase-1-financial-authorization-audit.md). The existing [financial mutation authorization matrix](docs/finance/financial-mutation-authorization-matrix.md) and authentication/authorization audit commit `90bcaf45266419d815b9ce4d4e231788cd4cc2c2` are supporting evidence, not sign-off by themselves.
- **Bulk Action 3:** focused source review documented; no new production-code defect confirmed in that bounded review. Final route/method/role coverage and uncertain-outcome tests remain part of Actions 1, 3, 4, 6 and 8.
- **Bulk Action 7:** closed for repository-verifiable acceptance on `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`, as recorded above. Later historical paragraphs labelling Action 7 “OPEN” refer to earlier checkpoints and are superseded by the closure entry. Deployment-only gates remain unpassed.
- **Bulk Action 8:** in progress. The financial source review is documented, but full named-route/method/role authorization coverage, cross-user resource substitution, staff permission escalation/revocation, current-main CI evidence, and reconciliation of all security findings remain open.
- **Security findings still open:** historical application-log exposure review/possible credential rotation; deterministic dependency installation and vulnerability scanning (root lockfiles were not found in the review); exhaustive authorization matrix; full end-to-end WebAuthn ceremony verification; upload/injection/secret scanning; and production PHP/runtime/cookie/configuration evidence. These must be closed with evidence or explicitly retained as accepted/deployment-gated risk, not silently waived.
- **CI:** [PHP 8.3/8.4 full suites and frontend build](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39732897103), [SQLite/MariaDB security suites](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39732897104), and [asset publication](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39732897118) are terminal-green for runtime-code SHA `bb558be45160d6794d1d469355bf2b3798869067`. Do not label the newer docs/log-hygiene head green on that evidence. No executable application change was made in this audit checkpoint; rerun all affected workflows on any new runtime-code SHA.
- **Deployment gates remain pending, not passed:** authorised live provider contracts, real-device SMS/WhatsApp delivery, HTTPS browser WebAuthn, and cPanel PHP/runtime/schema/environment/scheduler/queue/permissions checks. No live financial/provider transactions were initiated and no feature gates were opened.

### Reconciled current action status

| Action | Current status | Reason |
|---|---|---|
| 1. Transaction recovery/crash safety | In progress | Existing concurrency and crash-recovery tests pass on a prior tested code SHA; complete remaining cross-workflow late-response/recovery audit and record current-code evidence. |
| 2. Provider integration/failover | Open | Provider-specific contracts and safe retry/requery semantics are not verified for every provider; unverified purchases/failover remain gated. |
| 3. Wallet/payment integrity | Source audit documented; acceptance open | Core invariants and test coverage reviewed; route-by-route mutation/settlement acceptance still open. |
| 4. Payouts/transfers | Open | Strong service/concurrency controls exist; finish full endpoint/role and provider reconciliation acceptance without live money. |
| 5. Authentication/account security | Partially verified | Automated suites pass on a prior code SHA; full end-to-end WebAuthn and OTP vendor/device checks remain unverified. |
| 6. Roles/permissions/admin security | Open | Representative role tests exist; exhaustive route/method matrix, cross-user ID substitution and privilege escalation/revocation tests remain. |
| 7. Core platform/blueprint compliance | Closed for repository-verifiable acceptance | Closed on SHA `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`; does not imply deployment gates passed. |
| 8. Final integration/acceptance | In progress | Finish authorization/security findings reconciliation, record exact-current-code CI, then make a release decision from evidence. |

**Phase 1 remains OPEN.** Do not change the frozen theme, create/switch branches, perform destructive database operations, trigger live financial transactions, or enable live provider purchases during this closure work.
