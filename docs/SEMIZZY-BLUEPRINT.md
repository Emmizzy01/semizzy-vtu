# Semizzy VTU — Product & Implementation Blueprint

**Canonical repository:** https://github.com/Emmizzy01/semizzy-vtu  
**Canonical branch:** `main` only. Do not create or use feature branches.  
**Status:** Living specification. During implementation, complete the code, authorization, UI, failure handling, and documentation without running tests. Per the owner's explicit build-first rule, the comprehensive platform-wide test/verification/acceptance phase happens only after the entire platform is built. Checks requiring the live server are tracked separately for deployment.

## 1A. Production implementation standard — binding owner rule

SEMIZZY ONE is a real production platform, not a mock/demo. All Core functions and approved service addons must be implemented as real, durable, auditable production workflows. Mocks, fakes, simulated outcomes, and fixtures are permitted only in isolated automated tests or explicitly identified provider sandboxes; never present them as live functionality or substitute them into production code paths.

Every enabled action must have server-side validation and authorization, persistence, safe transaction handling where relevant, truthful failure states, idempotency/recovery for financial and provider operations, appropriate audit records, and deployment/configuration instructions. Do not run tests during implementation; test the whole platform after the full build. Missing credentials or unverified integrations must produce a truthful unavailable state and keep the affected capability gated—not fabricated success. Do not send real transactions during development verification. Actual live verification is a controlled deployment matter. A pending deployment check must not be misreported as a development failure, and a green build alone does not prove live activation.

## 1. Product direction

Semizzy is a modular Nigerian VTU and fintech platform for customers, resellers, agents, business teams, and authorised operations staff. The initial hosting target is Laravel on cPanel/shared hosting, with a path to managed VPS/cloud hosting. Stack: Laravel/PHP, Inertia.js, React, TypeScript, Vite, MySQL/MariaDB. UI must be mobile-first, accessible, and support both light/day and dark/night appearance.

### Non-negotiable engineering rules
- Work only in `Emmizzy01/semizzy-vtu`, on `main`. Never create another branch.
- Preserve existing working functionality; inspect the real code and tests before changing it.
- Use meaningful, coherent multi-file implementation batches. Do not run tests/build/CI checks during the build; comprehensive verification is reserved for after the full platform implementation.
- Enforce permissions on the server, not only by hiding UI links. Record sensitive administrative changes in audit trails.
- Wallet changes must be ledger-backed, idempotent, referenceable, reconcilable, and auditable. Never silently edit a wallet balance or credit funds solely because a receipt was uploaded.
- Never commit secrets, production credentials, customer data, database dumps, or private payment evidence.
- Track implementation separately from final verification. During the build, implement the backend, UI, authorization, failure handling, and docs without running tests. Final platform acceptance is after the complete build.
- Keep integrations modular and compatible with shared hosting; do not require an always-running worker unless documented as optional.
- No loans, forex, investment products, or unapproved escrow. Do not build unlawful OTP/account-access services.

## 1A. Active core build scope — explicitly not deferred to Phase 4

The following user-facing money and service workflows are part of the active core build. They must not be classified as Phase 4 marketplace-only work or left as “Coming Soon” merely because marketplace expansion is later:

- **Bulk purchase across supported services:** the agreed initial batch size is **10–100 items per batch**. Customers prepare multiple recipients/items and review the server-calculated total before confirmation. Use durable background processing for batches, with persisted batch/item status and safe progress/recovery polling. Validate every item before acceptance; each item must be independently tracked with stable idempotency and its own order/ledger records. Never debit a batch total without per-item records, never blindly retry an uncertain provider outcome, and report partial outcomes honestly. Only expose bulk purchase for service types whose individual purchase, verification, fulfilment and recovery paths are already enabled. Design for a future increase to 500 only after queue capacity, concurrency, timeout recovery and reconciliation tests pass.
- **P2P wallet transfer:** authenticated wallet-to-wallet transfers with recipient confirmation, transaction PIN, server-side limits, idempotency, a balanced ledger transfer, and clear sender/recipient history. No transfer to self, no direct balance writes, and no duplicate credit/debit.
- **Transfer to bank / withdrawals:** user-selected verified bank beneficiary, server-side bank/account validation, explicit fee/limit disclosure, PIN and risk checks, payout state tracking, reconciliation, and safe handling of pending/unknown provider outcomes. Do not report success until the payout provider confirms it.
- **Saved bank accounts:** users can add, verify, select and remove their own withdrawal beneficiaries. Ownership and verification status must be enforced server-side; mask sensitive account details in lists and logs.
- **Bank directory and account-name verification:** maintain a refreshable bank-code directory from an approved source and use a configured verification provider to resolve account name before withdrawal. Never invent account names or treat a client-submitted name as verified. Fail closed when verification is unavailable.
- **Dedicated virtual account numbers per user:** support two or more distinct dedicated deposit accounts for each user, across different supported banks/providers where available. Store each provider-issued account with its bank, account name, assigned user, provider reference, status, and verification timestamps; enforce uniqueness and ownership. Account provisioning must be idempotent, tolerate provider timeouts without duplicate creation, and support webhook signature validation, replay protection, reconciliation, and safe account replacement/deactivation. A virtual-account deposit must be credited only after a verified provider event/reconciliation confirms the funds, through the existing immutable wallet ledger exactly once. Do not generate fake account numbers or expose provider credentials. If the provider supports only one account per user, do not pretend the multi-bank requirement is met; configure multiple supported providers/banks or report the limitation clearly.
- **Referrals:** user-specific referral code/link, attribution and anti-self-referral/duplicate-account checks, transparent reward rules, and ledger-backed reward settlement only after the configured qualifying event. Reward amounts and qualifying conditions must be admin-configurable; do not hardcode or pay an unverified referral.
- **Automatic network and biller/account verification:** suggest a mobile network from Nigerian number prefixes where possible, but allow correction because number portability means prefix detection is not authoritative. Use provider-backed verification for electricity meter/IUC/smartcard and other supported biller identifiers; distinguish verified, unverified and unknown, and block purchase when verification is required but inconclusive.
- **Saved service beneficiaries across the platform:** users can save named recipients/identifiers for every supported service category (phone numbers, electricity meters, TV smartcards/IUC, education and other service-specific identifiers), select them again at checkout, and remove them. Scope every record to its owner, keep service type and safe service-specific details, never store transaction PINs/payment credentials, and validate current product/provider compatibility when reused.
- **Scheduled purchases across supported services:** allow users to schedule one-time future or recurring daily/weekly/monthly purchases where the underlying service has a verified purchase handler. Require transaction PIN at schedule creation, persist schedule state, run through the same service coordinator, wallet ledger, provider verification, idempotency and reconciliation path as immediate purchases, and show/cancel schedules. Never charge for a schedule merely because its time arrived; revalidate product, pricing, recipient and available wallet funds. Pause schedules when an outcome is pending/unknown, avoid catch-up bursts after downtime, and add service-specific handlers before claiming coverage for any category.

These are core wallet, account and service capabilities, not a reason to delay the Unified Marketplace phase. Implementation must proceed in coherent batches with migrations, backend authorization, customer UI, tests, and operator/deployment notes. Provider-backed money movement remains disabled until the relevant provider contract, credentials, reconciliation and tests are verified. Never run live transactions as a substitute for automated tests.

## 1B. Development versus deployment-stage acceptance — binding owner rule

**Anything that needs the live server, cPanel, or deployed environment to test, inspect, or verify is a deployment matter, not an unfinished development task.** This applies across all phases, bulk actions, audits, and status reports. Do not leave a development phase open solely because a live-only check has not yet been run.

During development, complete all checks available through repository inspection, automated tests, static/security analysis, builds, isolated test environments, and provider sandboxes. Track live-only work under **Deployment checklist — execute during deployment** and label it **Deferred to deployment / not yet run** until verified on the real environment. Do not claim a live check passed without evidence.

Deployment-only examples include cPanel/runtime and permissions, release commit and deployed files, production environment values, production database/schema and migration status, deployed assets/routes, live HTTPS/passkeys, authorised real-device OTP delivery, host cron/scheduler/queue behavior, production logs, backup/restore validation, smoke tests, and authorised live-provider verification.

Do not access or change production, alter production data, execute migrations, or trigger real transactions merely to turn a deployment checklist green. Follow the backup, migration review, operator approval, and financial safety rules. A deployment-only check may gate activation of the affected live capability, but must not block unrelated development. A code push or green CI does not prove that cPanel is serving that release.

## 1C. Addon conversion and dashboard theme freeze

- Continue the agreed **20-phase roadmap** for converting all approved in-scope services into independent addons. Resume from the actual evidenced checkpoint; do not restart phases or replace the agreed roadmap with a new one.
- Core retains ownership of identity/authentication, users/roles/permissions/tier policy, wallet and immutable ledger, funding/payments/payouts/transfers, pricing, global settings, security/audit, notifications, transaction recovery, and addon management.
- Each service addon owns its service-specific catalogue, customer inputs, orders, provider adapter, service admin controls, and service-specific recovery orchestration. It must use Core contracts and must not duplicate Core-owned functionality.
- **The existing user and admin dashboard themes/design are frozen.** Do not redesign, restyle, replace, or change the current dashboard theme while doing addon conversion unless the owner explicitly authorizes it. Preserve existing working behavior and route/API compatibility.


## 1D. Production platform updates and current implementation evidence — 22 October 2026

These updates supplement Master Blueprint 1.2 and are binding for ongoing implementation. They describe intended behavior and the observed repository state; a code registration or green CI run is not proof of end-to-end or live production readiness.

### Production execution rules
- Every production action must execute a real server-side workflow with authorization, validation, durable persistence, truthful status/failure handling, relevant audit records, and automated tests. Financial/provider operations require idempotency, concurrency protection, reconciliation, and safe recovery appropriate to the operation.
- Mocks/fakes/fixtures/simulated outcomes may exist only in isolated automated tests or explicitly identified provider sandboxes. No fabricated success, fake account numbers, fake balances, invented provider responses, or in-memory-only substitutes in live code paths.
- Missing credentials, unsupported provider contracts, or unverified integrations must fail truthfully and keep only the affected capability gated. Never submit real financial/provider transactions merely to test code.
- The target platform is production—not a demo. “Implemented,” “CI passed,” “deployment checked,” and “production verified” are distinct statuses and must be reported separately.

### Core and service-addon ownership
- Core exclusively owns identity/authentication, users and roles/permissions, tier/KYC policy, wallet and immutable ledger, funding/payments/payouts/transfers, pricing, global settings, security/audit, notifications, transaction recovery, and addon management.
- Each approved service addon owns its own service catalogue, customer inputs, service orders, provider adapter, admin controls, and service-specific recovery orchestration. Addons must call Core contracts and must not duplicate Core-owned systems.
- Government Documents & Registration is the explicit provider-ownership exception: use the authorized central government workflow, approved agency integrations, or reviewed manual processing. Do not invent a provider integration.
- All approved in-scope services are to be converted to independent addons under the already-agreed 20-phase roadmap. Continue from the latest evidenced checkpoint; do not restart, renumber, or replace that roadmap. No new addon manager or arbitrary uploaded-code execution is authorized.
- The existing user/admin dashboard theme and layout are frozen. Preserve routes, working behavior, data, and established UI; no redesign or restyling without explicit owner approval.

### Addon registry implementation update
- The existing addon registry is a Core-owned manifest/dependency/compatibility and administration surface. A database manifest alone does not mean executable service code is installed, enabled, or production-ready. The registry is not an arbitrary package installer or dynamic PHP/JavaScript loader.
- The repository now has an explicit reviewed-code catalogue at `app/Services/Addons/AddonImplementationRegistry.php`, initially mapping VTU, Education, Streaming, and VTU Website Requests to existing application handlers and named operations. The admin addon screen exposes implementation readiness; enabling an unregistered implementation is rejected.
- Unit coverage has been added for registered and unknown addon keys and handler mappings. These changes are committed on `main`; their latest CI runs must be checked before claiming tests pass.
- This is an initial implementation-registration step, not completion of the 20-phase conversion. The lifecycle policy still needs to be connected carefully to new-operation entry points after route/job/provider inventories and characterization tests are complete. Do not block webhooks, reconciliation, accepted-order recovery, refunds, or other Core recovery paths when an addon is disabled.
- Migrate one bounded service at a time through compatibility-preserving delegation. Preserve existing route names, request/response contracts, permissions, idempotency, accepted transaction recovery and customer/admin behavior. Expand only after focused tests, full CI, concurrency checks, and frontend build evidence.

### Deployment checklist classification
- Anything that can only be tested or inspected on cPanel/live hosting belongs in **Deployment checklist — execute during deployment**, marked **Deferred to deployment / not yet run** until evidence is collected. It is not a development-phase failure and must not keep unrelated development open.
- Examples include deployed commit/files/assets/routes, PHP extensions/permissions, production environment and database schema/migrations, HTTPS passkeys, authorized real-device SMS/WhatsApp delivery, cron/queues/scheduler, production logs, backup/restore validation, smoke tests, and authorized live-provider checks.
- Never claim deployment verification from a GitHub push or green CI alone. Production data/schema changes require reviewed forward migrations, a verified recoverable backup, and explicit authorized operator approval; never reset, truncate, drop, or blindly reseed production data.

### Scope and service availability
- Preserve approved service scope, but do not restore previously removed standalone loans, forex, investment, website-builder, domains, or hosting products without explicit owner approval. Regulated or unsupported money movement stays gated. Do not build unlawful OTP/account-access services.
- Provider credentials and live readiness are per service/provider; never expose provider names or credentials to customers where the existing customer-facing contract requires abstraction. Keep unverified providers disabled and preserve safe reconciliation for accepted work.
- Bulk purchase remains **10–100 items per batch** under the current canonical blueprint; a 500-item ceiling is not authorized until queue capacity, concurrency, timeout recovery and reconciliation evidence justify an explicit blueprint change.

---

## 2. Accounts, user types, and levels

Separate **account type** (what the user does) from **verification/transaction level** (what limits and checks apply). Never use a user-selectable type as proof of authority.

### Account types
- **Customer:** purchase available VTU services, manage wallet, view receipts/history, submit funding evidence, open support tickets, manage profile/security.
- **Reseller:** customer capabilities plus eligible reseller pricing, markup/commission views, sales/order reporting, and applicable transaction limits.
- **Agent:** approved agent workflows, assigned customer/service scope, commission reporting, and controlled operational actions.
- **Business/team account:** approved business profile and team members with explicitly scoped roles; no shared passwords.
- **Support staff:** support tickets, communication, and permitted account lookup; no wallet credit or unrestricted financial actions by default.
- **Finance/operations staff:** funding/deposit review, reconciliation, refund/payment operations only when explicitly granted.
- **Compliance/security staff:** security events, risk review, privacy/data-request workflows, incident evidence, and restricted investigations.
- **ADMIN:** platform administration limited by explicit server-side permissions; sensitive financial actions require separation of duties and approval where configured.
- **STAFF:** explicitly granted operational duties only; no implied unrestricted admin access.
- **SUPPORT:** customer support and permitted account lookup; no default wallet-credit or payout authority.
- **USER:** customer-facing account and service actions allowed by account state, tier, KYC and feature/provider gates.

Do not introduce a `SUPER_ADMIN` role. The canonical roles are `ADMIN`, `STAFF`, `SUPPORT`, and `USER`; additional capabilities are permissions, not extra implicit roles.

Staff access is permission-based and least-privilege. Admin dashboard access is not a blanket grant to every admin operation.

### Transaction levels
Use the canonical customer tiers **Tier1, Tier2, Tier3, and Tier4/Merchant**. Never expose legacy labels such as `level_1` through `level_4` in customer-facing UI. Read exact limits, KYC requirements, and eligibility from the configured database policy; never invent or hardcode tier limits. Tier membership does not grant staff roles or permissions. Enforce tier policy server-side at every relevant transaction boundary, including aggregate daily usage, currency/minor-unit correctness, and KYC state. Policy edits require validation, a reason, and an audit record. Never invent identity/KYC verification.

## 3. Manual bank deposit and funding instructions

### Customer flow
1. Show only the currently authorised deposit instructions: bank name, account name, account number, exact-transfer instructions, and support guidance.
2. Customer submits amount, transfer reference, and proof/evidence where configured. Validate ownership, file size/type, amount bounds, reference format, and request throttling.
3. Generate a unique public deposit reference and record the request as pending review. Display status and timestamps.
4. Uploading evidence never credits the wallet. A successful credit requires authorised staff review and verified payment evidence.
5. Protect against duplicate submissions and replay: unique/idempotent reference checks, locked state transitions, and a one-time credit invariant.
6. Rejection requires a reason; users can see a safe, useful status/reason. Do not expose staff-only evidence or internal risk notes.

### Finance/admin flow
- Configure enable/disable, bank name, account name, account number, and customer instructions.
- Validate required banking details before enabling the channel.
- Review evidence with permission checks; approve/reject with reason and actor/time audit.
- Approval and ledger credit occur in one database transaction with locking/idempotency. Use the existing wallet ledger service; never write balances directly.
- Support reconciliation, duplicate detection, review queues, immutable history, and operational alerts.
- Changes to payout/funding instructions must be audited. Never expose account secrets beyond the authorised customer-facing instructions.

## 4. Service catalogue and icon-led navigation

The service catalogue is a single, searchable, responsive, icon-led entry point. Each item has a clear label, description, category, destination, status, and a reason when unavailable. Use the existing Lucide icon package consistently, with accessible names and keyboard-visible focus.

### Core customer services
- Airtime & data (only when its provider/service integration is enabled and operational).
- Manual bank deposit (only when enabled).
- Transactions & orders.
- Wallet and funding history.
- Transaction PIN/security.
- Electricity bills, cable TV, internet bills, and other bill payment categories only when the required provider catalogue, validation, fulfilment, and reconciliation are implemented.
- Support/help, notifications, profile, account/security settings.
- Marketplace and reseller/business tools only where their actual workflows and permissions exist.

Never present a roadmap-only service as available. Disabled or in-progress services must not submit to a fake endpoint or suggest a payment can be completed.

## 5. Transaction security

- Separate four-digit transaction PIN, stored as a password hash and never returned to the client.
- Validate PIN quality and confirmation; require current PIN for changes after setup.
- Rate-limit attempts, persist failed-attempt counters, temporarily lock after repeated failures, and audit security-relevant events without recording the PIN.
- Require PIN verification at each applicable sensitive transaction boundary (not merely on the PIN settings page). Verify before irreversible wallet/service actions.
- Apply request throttles, idempotency keys/references, duplicate protection, server-side ownership checks, and transaction-level policy limits.
- Add step-up verification for high-risk changes where supported; protect password/session changes and provide safe session/device visibility/revocation where implemented.
- Log security events with actor, event type, timestamp, and safe context. Restrict event access to security-authorised staff.
- Do not claim device fingerprinting, MFA, risk scoring, or step-up checks exist until implemented and tested.

## 6. Site-wide admin controls

Admin settings should cover:
- Site identity and support contacts.
- Maintenance mode and customer-facing maintenance notice.
- Default appearance skin and user appearance preference (appearance-only).
- Service availability and feature flags.
- Manual deposit configuration/instructions.
- Provider/integration status and secret configuration through secure environment/secret management, never plaintext source control.
- Product plans, pricing/markups and commissions when supported.
- User/account state, account types, level policies, staff permissions.
- Wallet/deposit/withdrawal/refund operations, transaction recovery, reconciliation, audit trail, and security events.
- Support, notifications, global search, reports/BI, privacy/data-request and incident workflows.
- Add-on/feature dependency checks, rollout controls, and clear distinction between core, implemented, and roadmap-only features.

All sensitive setting changes require server-side permission checks, validation, a reason where appropriate, transactional persistence, and an audit record containing actor, key, old/new values with sensitive values redacted, and timestamp. Enabling a feature must validate dependencies; disabling a feature must not strand enabled dependents. Core/roadmap-only features cannot be toggled as if operational. Maintenance mode must not lock authorised staff out of recovery paths.

## 7. Customer dashboard

Provide a responsive dashboard with:
- Wallet balances by currency, safely sourced from wallet/ledger state.
- Recent transactions, funding/deposit statuses, VTU orders, receipts and clear references.
- Service shortcuts that reflect actual enabled features and permissions.
- Deposit/funding instructions and pending-review states.
- Transaction PIN/security status, account verification/level, notifications, and support entry points.
- Useful empty, loading, validation, unavailable, and failure states; accessible mobile navigation.
- No fake metrics, hidden fees, or claims that pending funds are available.

## 8. Admin dashboard and global workspace

Provide an operational overview of users (only for permitted staff), wallets, pending funding, deposits awaiting review, refunds needing action, unresolved service orders, provider health/reconciliation, support queue, and security alerts. Each metric must link to an authorised working workflow. Avoid leaking counts or personal data to staff without permission.

Global navigation/search must be permission-aware and include implemented areas such as:
- User and staff access management.
- Manual deposit review and deposit settings.
- Wallet ledger/reconciliation, funding, refunds and transaction recovery.
- VTU orders, provider integrations and service configuration.
- Platform settings, feature controls and level policies.
- Support, reports, audit/security events and privacy/compliance workflows.

A visible menu item is not proof of a complete workflow. Every route must enforce server-side authorization and use explicit state transitions.

## 9. Appearance system

Support both Day/Light and Night/Dark for each approved visual skin:
1. Modern Green & White
2. Premium Navy & Teal
3. Minimal Blue & White
4. Obsidian Luxe
5. Arctic Light

Keep the theme choice accessible and persistent. Global default is controlled by authorised administrators; a user's preference can override it. Theme changes must only affect appearance, never financial or access behaviour. Use consistent design tokens, contrast, responsive layouts, and clear focus states.

## 10. Audit, privacy, and operations

- Maintain audit trails for administrative settings, staff permissions, level policies, deposit review, refunds, and security actions.
- Keep personal-data access requests, consent records, retention controls, vulnerability intake, patch prioritisation, penetration-test remediation, incident response/evidence, posture reporting, and risk trends in the roadmap and implement them with appropriate restricted access.
- Minimise personal data in logs, exports, and error messages. Protect uploaded proof files and prevent public access.
- Use explicit states, retries, reconciliation, idempotency, and operational visibility for service orders and financial workflows.

## 11. Acceptance checklist for the six requested workstreams

### A. Manual deposit with instructions
- [ ] Customer sees current admin-managed instructions.
- [ ] Customer can submit a validated deposit request and proof.
- [ ] Request status/history and rejection reason are shown safely.
- [ ] Staff review/approval/rejection is permission-gated and audited.
- [ ] Credit uses ledger service, transaction locking, and one-time idempotency.
- [ ] Duplicate references and replay cannot credit twice.

### B. Icon-led services list
- [ ] Searchable/category-aware list with consistent accessible icons.
- [ ] Status reflects real configuration and integration health.
- [ ] Working destinations only; unavailable services explain why.
- [ ] Responsive on small screens and uses the approved theme system.

### C. Transaction security
- [ ] PIN setup/change using a four-digit PIN, hash storage, predictable-PIN rejection.
- [ ] Attempt throttling, lockout, security audit.
- [ ] PIN enforced on applicable transaction endpoints.
- [ ] Ownership, level limits, idempotency and step-up checks where supported.
- [ ] Tests cover failed, locked, replayed, and successful cases.

### D. Site controls
- [ ] Authorised settings UI and backend validation.
- [ ] Maintenance, site identity/support, theme defaults, service controls.
- [ ] Feature dependency and implementation-state checks.
- [ ] Audited changes, reason, safe secret handling, recovery access.
- [ ] Tests cover permission denial and invalid/dependent changes.

### E. User type and level
- [ ] Account type is distinct from level.
- [ ] Staff permissions are explicit and server-enforced.
- [ ] Level policies and KYC/transaction limits are enforced at transaction boundaries.
- [ ] User/admin views reflect current state without privilege escalation.
- [ ] Tests cover role/level boundaries.

### F. Customer/admin dashboards and global features
- [ ] Dashboard metrics come from real persisted data.
- [ ] Navigation and actions lead to implemented, authorised workflows.
- [ ] Audit, support, security, funding, orders, reports and settings integrate coherently.
- [ ] All relevant tests and frontend production build pass.

## 12. Delivery discipline

Implement in meaningful consolidated batches directly on `main`. Before each batch, inspect relevant code and tests; after each batch, run available focused tests, security checks, and frontend/CI checks. Record what changed, actual test results, commit SHA, development gaps, and deployment-deferred checks in separate categories. Do not claim completion for unchecked work, but do not classify live-server-only tasks as incomplete development or pause unrelated implementation for them. Update this blueprint when scope or decisions change.
