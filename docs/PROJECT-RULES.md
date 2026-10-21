# SEMIZZY ONE — Binding Project Rules

**Canonical repository:** https://github.com/Emmizzy01/semizzy-vtu  
**Only permitted branch:** `main`  
**Product direction:** SEMIZZY ONE, under SEMIZZY WEBMASTER — “We Design. We Develop. We Deliver.”

These rules apply to all future implementation, maintenance, database evolution, testing, and deployment work. If another note conflicts with these rules, preserve customer data and financial integrity first, and work directly on `main`.

## 1. Production-grade implementation is the default

- Build and maintain the application as a real production product, not a demo. Do not substitute simulated balances, fake provider results, fabricated delivery/status progress, or placeholder controls for real functionality.
- A feature is not complete until its end-to-end flow exists: UI, server-side validation, authorization, persistence, failure states, auditability where appropriate, automated tests, and deployment/configuration notes.
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
- Keep high-risk, regulated, or unverified services disabled until the specific service flow, provider integration, permissions, reconciliation, and tests have been reviewed.
- Do not describe a provider integration as live unless credentials/configuration and a safe, authorised end-to-end verification establish that it is live.

## 7. Deployment and hosting compatibility

- Primary deployment target remains Laravel on cPanel/shared hosting with MySQL/MariaDB; keep a documented path to VPS/cloud hosting.
- Do not introduce mandatory infrastructure (for example, always-running workers, unsupported PHP extensions, or Node build tooling on production) without documenting availability and a compatible fallback or deployment process.
- Keep production environment files outside the public web root, use HTTPS, disable debug output in production, restrict filesystem/database permissions, and protect backups outside public access.
- Maintain a deployment checklist covering release commit, PHP/extensions, frontend build assets, environment/config, migration status, storage permissions, queues/scheduler if used, logs, backups, smoke checks, and recovery.
- A backup is only useful if its existence, integrity, access, and restoration procedure are checked. Never test restore by overwriting the live database.

## 7A. Development completion versus deployment-stage work — binding rule

- **Live-server-dependent work is deployment-stage work, not an incomplete development task.** If a check or action can only be completed against the deployed cPanel/live environment, mark it as a deployment checklist item and defer execution until deployment/production verification. Do not count it as an unfinished development blocker or stop unrelated implementation because it cannot be checked locally or in CI.
- Examples include inspecting production-only environment values, cPanel/PHP runtime and permissions, deployed asset/route smoke checks, live HTTPS passkey/browser behavior, real SMS/WhatsApp delivery to an authorised device, production scheduler/queue operation, and authorised live-provider contract checks.
- During development, complete everything that can be completed without the live server: implementation, code/config validation, automated tests, static/security checks, build checks, provider mocks/sandbox tests, recovery/reconciliation logic, documentation, and a precise deployment checklist for the remaining live-only steps.
- Keep deployment-stage items visible in a clearly labelled **Deployment checklist / deferred to deployment** section. Do not label them as failed, incomplete development, or release blockers to unrelated work merely because they require the live server.
- A live-only check must not be claimed as passed before it is executed. This rule changes task classification, not truthfulness: report it as “deferred to deployment / not yet run on live environment.”
- Deployment-stage items do not prevent completion of independent development batches. They may still govern whether a specific live capability (for example, provider purchases or payouts) can be activated at deployment.
- Do not access or change the production server, alter production data, run migrations, or trigger real transactions merely to turn a deployment checklist item green during development. Live operations must follow the existing authorisation, backup, migration, and financial-safety rules.

## 8. Verification and release acceptance

- Run the relevant PHP/Laravel tests, frontend checks/build, and targeted regression tests for touched financial, security, provider, and admin workflows.
- Check GitHub Actions on the exact commit being reported. Distinguish passed, failed, in-progress, skipped, and not-run checks.
- Verify responsive rendering and real route/permission behaviour when UI changes are involved; do not infer visual correctness from a successful build alone.
- Report exactly what changed, the commit SHA/link, checks performed and their actual results, and separate (a) development/CI gaps that still need work from (b) deployment-stage checks deferred until live deployment. Do not mix the two categories.
- Do not state “production-ready” as a blanket guarantee. Use it only for the verified scope and clearly list anything still awaiting provider approval, credentials, operational configuration, or live-environment validation.

## 9. Product continuity

- Use `docs/SEMIZZY-BLUEPRINT.md` as the canonical product acceptance checklist.
- Keep the core responsible for shared identity, users, wallet, pricing, settings, and add-on management. Add-ons must integrate with core contracts rather than duplicate these systems.
- Preserve the agreed SEMIZZY ONE scope, roles, account types/levels, security controls, service catalogue, admin controls, themes, and deployment constraints unless the project owner explicitly changes the blueprint.
- Future updates must include any required schema/data migration and update tests/docs alongside the feature; do not leave the database behind the application code.
