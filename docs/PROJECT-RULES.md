# SEMIZZY ONE — Binding Project Rules

**Canonical repository:** https://github.com/Emmizzy01/semizzy-vtu  
**Only permitted branch:** `main`  
**Product direction:** SEMIZZY ONE, under SEMIZZY WEBMASTER — “We Design. We Develop. We Deliver.”

These rules apply to all future implementation, maintenance, database evolution, testing, and deployment work. If another note conflicts with these rules, preserve customer data and financial integrity first, and work directly on `main`.

## 1. Production-grade implementation is mandatory

**SEMIZZY ONE is a real production platform, not a mock/demo.** All production code paths must perform real, durable, auditable work through verified integrations and persisted state. Mocks, fakes, fixtures, simulated provider outcomes, fabricated transaction states, and in-memory substitutes are permitted only in isolated automated tests or explicitly identified provider sandbox environments. They must never be represented as live functionality or silently substitute for unavailable production integrations.

- Every enabled action must have end-to-end implementation: UI, server-side validation and authorization, durable persistence, safe state transitions, failure/recovery handling, auditability where appropriate, and deployment/configuration instructions. Per owner instruction, do not execute tests during the implementation phase; platform-wide testing is reserved until the full platform build is complete.
- No placeholder control may be presented as operational. If credentials, provider support, or required configuration is missing, return a truthful unavailable state and keep the affected capability disabled rather than fabricating success.
- Automated tests and sandbox checks must not send real money or real provider transactions. Live verification belongs to controlled deployment tasks under Section 7A.
- Production-grade implementation does not bypass safety gates: unverified integrations, ambiguous outcomes, missing credentials, or incomplete reconciliation remain gated. The code can be implemented while live activation awaits deployment verification.
- This standard applies to Core and every approved addon, including funding, wallet movements, purchases, refunds, payouts, OTP, passkeys, admin controls, background jobs, catalogue sync, and recovery.

- Build and maintain the application as a real production product, not a demo. Do not substitute simulated balances, fake provider results, fabricated delivery/status progress, or placeholder controls for real functionality.
- A feature's implementation milestone is tracked when its end-to-end flow exists: UI, server-side validation, authorization, persistence, failure states, auditability where appropriate, and deployment/configuration notes. Do not run tests while building; do not claim tested/verified status. Overall acceptance follows the single comprehensive platform-wide testing phase after the full build.
- Keep production-safe configuration and real integrations separate from test/sandbox mode. Never send a real payment, purchase, refund, or provider request as part of routine verification.
- Never claim a specific capability or release is verified when its required development/CI checks have not passed. Classify live-server-only checks as deployment-stage items under Section 7A; they do not block unrelated development, but must be run at deployment before enabling the affected live capability.
- Preserve the existing project blueprint and working features. Do not remove agreed functionality just to simplify or consolidate implementation.

## 2. GitHub and change control

- All work belongs in https://github.com/Emmizzy01/semizzy-vtu on `main` only.
- Never create, switch to, or commit to another branch. Do not create pull requests as a substitute for direct commits to `main`.
- Inspect current code and tests before editing. Make coherent, reviewable commits with descriptive messages; keep unrelated changes separate where practical.
- Never commit secrets, production credentials, real customer data, database exports, payment evidence, or environment files containing secrets.
- After a meaningful change, inspect the resulting commit and relevant GitHub Actions runs. Fix failures rather than reporting them as passed.

## 3. Database is a maintained, evolving part of the product

- Keep the database aligned with the implemented product and its future releases. When schema or baseline reference data needs to change, include the appropriate versioned Laravel migration and, where suitable, an idempotent seeder or documented data migration.
- Prefer additive, forward-compatible migrations. Include suitable foreign keys, unique constraints, indexes, nullability/default handling, and transaction safety for the actual MySQL/MariaDB deployment target.
- Make seeders safe to re-run; do not overwrite live customer settings, user records, wallet balances, provider credentials, transaction history, or admin changes with development defaults.
- Treat existing production records as valuable. Never use `migrate:fresh`, `db:wipe`, table drops, destructive reseeding, or blanket truncation against production. Never delete or fabricate migration history to silence an error.
- Before any production schema/data change: inspect migration status and actual schema, review the migration for locks/data loss/compatibility, verify a recoverable backup, and have the authorised operator approve the live operation. Apply only the intended incremental migration, then verify migration status, critical tables, and application health.
- Do not run production migrations merely because code was pushed. A code commit does not itself authorize a live database operation. Document the required command and release order, including any maintenance window or rollback/forward-fix plan.
- Keep test database setup isolated from production. Tests must not require destructive operations on the live database.
- Add future data needs through reviewed migrations and documented data transformations so updates do not require rebuilding the database from scratch.

## 4. Money, wallet, deposits, and transactions

- Use the wallet ledger as the auditable source of financial movement; enforce double-entry/accounting invariants where specified by the implementation.
- Protect every financial write with server-side authorization, transaction boundaries, row locking or equivalent concurrency controls, idempotency/replay protection, and appropriate unique database constraints.
- Reconcile provider and internal states. Never blindly retry an ambiguous payment or purchase, never credit a wallet solely because a receipt was uploaded, and never silently edit a balance.
- Keep manual deposits pending until an authorised review confirms the evidence/reference and approves the credit. Prevent cross-customer reference reuse and duplicate approvals.
- Test Mode must be explicit and selectable where required before live gateway credentials are configured. Never expose secrets to the browser, logs, responses, or Git history.
- Test workflows with fakes, sandbox providers, and isolated test data only. No live transactions during routine development or verification.

## 5. Security, access, and privacy

- Enforce roles and permissions on the server for every protected action. Hiding a menu item is not authorization.
- Apply least privilege, sensitive-action audit logs, validation, rate limits, session/device protections, transaction PIN/2FA policies as specified, and safe error messages.
- Keep user type separate from verification/transaction level; neither a user-selected label nor a UI badge grants authority or bypasses limits.
- Protect personal/identity information and comply with applicable privacy and retention requirements. Do not log passwords, OTPs, PINs, access tokens, full payment credentials, or unnecessary personal data.

## 6. Provider and service safety

- Keep provider adapters and service catalogues modular; validate product/service availability, customer inputs, price/limits, and provider response before debiting or fulfilling an order.
- Use explicit order state transitions and reconciliation. Do not blindly resubmit an order after an uncertain provider outcome; provide safe status recovery.
- Keep high-risk, regulated, or unverified services disabled until the specific service flow, provider integration, permissions, and reconciliation safeguards are implemented. Testing/review occurs in the final platform-wide acceptance phase.
- Do not describe a provider integration as live unless credentials/configuration and a safe, authorised end-to-end verification establish that it is live.

## 7. Deployment and hosting compatibility

- Primary deployment target remains Laravel on cPanel/shared hosting with MySQL/MariaDB; keep a documented path to VPS/cloud hosting.
- Do not introduce mandatory infrastructure (for example, always-running workers, unsupported PHP extensions, or Node build tooling on production) without documenting availability and a compatible fallback or deployment process.
- Keep production environment files outside the public web root, use HTTPS, disable debug output in production, restrict filesystem/database permissions, and protect backups outside public access.
- Maintain a deployment checklist covering release commit, PHP/extensions, frontend build assets, environment/config, migration status, storage permissions, queues/scheduler if used, logs, backups, smoke checks, and recovery.
- A backup is only useful if its existence, integrity, access, and restoration procedure are checked. Never test restore by overwriting the live database.

## 7A. Development completion versus deployment-stage work — binding rule

**Owner's explicit rule: anything that requires the live server, cPanel, or the deployed environment to test, inspect, or verify is a deployment matter. It is not an unfinished development task and must not keep a development phase or bulk action open.** Apply this classification consistently across every phase, audit, checklist, status report, and acceptance register.

- Examples include cPanel deployment configuration, production PHP/extensions and permissions, deployed code/assets/routes, production database connectivity/schema/migration status, live logs, HTTPS/browser passkeys, authorised real-device SMS/WhatsApp delivery, cron/scheduler/queue operation, backups/restore validation, production smoke tests, and authorised live-provider checks.
- During implementation, focus on code, static source inspection, safe architecture, migration review, recovery logic, documentation, and a precise deployment checklist. Do not run tests, CI test workflows, builds, or verification suites while the platform is still being built. Run the comprehensive test/build/acceptance campaign only after the entire platform implementation is complete.
- Record live-only checks under **Deployment checklist — execute during deployment** as **Deferred to deployment / not yet run** until actual evidence exists. Never call them development failures, and never claim them passed before execution.
- Do not reopen or block unrelated development phases because a deployment-only check is pending. A particular capability can nevertheless remain disabled at deployment until its own safety gate is satisfied.
- Do not access/change production, run migrations, alter production data, or trigger real financial/provider transactions just to change a checklist status. Follow backup, review, approval, and financial-safety requirements.
- Deployment verification is still mandatory before claiming the deployed release is verified. A successful code push or CI run alone does not prove that cPanel is serving that commit.
- Report development/CI gaps separately from deployment matters. Do not state “production-ready” as a blanket guarantee.

## 8. Verification and release acceptance

- Run the relevant PHP/Laravel tests, frontend checks/build, and targeted regression tests for touched financial, security, provider, and admin workflows.
- Check GitHub Actions on the exact commit being reported. Distinguish passed, failed, in-progress, skipped, and not-run checks.
- Verify responsive rendering and real route/permission behaviour when UI changes are involved; do not infer visual correctness from a successful build alone.
- Report exactly what changed, the commit SHA/link, checks performed and their actual results, and separate (a) development/CI gaps that still need work from (b) deployment-stage checks deferred until live deployment. Do not mix the two categories.
- Do not state “production-ready” as a blanket guarantee. Use it only for the verified scope and clearly list anything still awaiting provider approval, credentials, operational configuration, or live-environment validation.

## 9. Product continuity

- Use `docs/SEMIZZY-BLUEPRINT.md` as the canonical product acceptance checklist, alongside the owner's latest explicit decisions recorded in the project documents.
- Keep Core responsible for shared identity, users, roles/permissions, tier policy, wallet and immutable ledger, payments/funding/payouts/transfers, pricing, global settings, security/audit, notifications, transaction recovery, and addon management. Service addons own service-specific catalogues, inputs, orders, provider adapters, service admin controls, and service recovery orchestration; addons must use Core contracts and must not duplicate Core-owned systems.
- The agreed service architecture is conversion of every approved in-scope service to an independent addon under the agreed 20-phase roadmap. Continue from the current evidenced phase/checkpoint; do not restart completed work or invent a new roadmap. Verify the actual roadmap/source state before claiming any phase complete.
- **Freeze the existing user and admin dashboard themes/design.** Do not redesign, restyle, replace layouts, or make theme changes during addon conversion unless the owner explicitly authorizes it. Functional changes must preserve existing routes, working behavior, and established dashboard layouts.
- Preserve the agreed SEMIZZY ONE scope, roles, account types/levels, security controls, service catalogue, admin controls, and deployment constraints unless the project owner explicitly changes the blueprint.
- Future updates must include any required schema/data migration and update tests/docs alongside the feature; do not leave the database behind the application code.

