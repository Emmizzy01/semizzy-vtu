# SEMIZZY ONE — Phase 1 Security Findings Register

**Repository:** Emmizzy01/semizzy-vtu  
**Branch policy:** `main` only  
**Initial review base:** `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`  
**Register status:** Initial reconnaissance; not a penetration-test report or production sign-off.  
**Last updated:** 2026-10-22

## Status definitions

- **Open:** requires verification or remediation.
- **In progress:** a repository-level mitigation has been committed, but follow-up evidence remains.
- **Needs hosting evidence:** cannot be confirmed from repository files alone.
- **Unverified:** code inspection alone is insufficient; targeted tests or runtime evidence are required.

## Findings

| ID | Severity | Area | Evidence / observation | Required action | Status |
|---|---|---|---|---|---|
| SEC-001 | Critical / urgent | PHP2Shell runtime | Previously reported cPanel PHP version was 8.4.22. PHP's 2026-10-21 advisory identifies fixed upstream releases 8.4.27, 8.3.36, 8.2.35 and 8.5.12. The live runtime and vendor backport status have not been verified. | Hosting provider must confirm the actual web PHP handler/runtime, exact vendor build and patch status; patch immediately if not fixed; verify CLI/cron/worker runtimes separately and restart workers as required. Preserve logs and assess for compromise if exposure occurred. | Needs hosting evidence |
| SEC-002 | High pending content review | Repository hygiene / log exposure | Root-level `laravel (8).log` was tracked at the review base (110,389 characters) and contained error entries/stack traces. Pattern checks do not prove all sensitive data is absent. | Removed from current `main` tree in commit `b55f6b467fdca296e68159e0390c1b6e947a8fa2`; exact filename ignored in `cc74bd1743594f21b086ba4638d05e18a43b3f93`. Review Git history/clones/caches and rotate any confirmed leaked credentials; follow incident response if sensitive values are found. | In progress — current tree cleaned; history/content review open |
| SEC-003 | Medium | Dependency reproducibility | `composer.lock` and `package-lock.json` were not found at the repository root during the initial check. CI currently uses `composer install` and `npm install`. | Confirm intended package manager and lockfile policy. Commit reviewed lockfiles where appropriate; enforce deterministic installs and dependency vulnerability checks. Do not update dependency versions blindly during the security audit. | Open |
| SEC-004 | Medium / verify | Production configuration | `config/app.php` defaults `APP_DEBUG` to false; `phpunit.xml` explicitly sets testing environment. Repository review cannot establish the live production environment values. | Verify production `APP_ENV=production`, `APP_DEBUG=false`, HTTPS and secure cookie settings, protected `.env`, least-privilege filesystem permissions, and that backups/Git metadata are not publicly served. Never publish secrets in evidence. | Needs hosting evidence |
| SEC-005 | High / verify | Authorization boundaries | `routes/web.php` includes authenticated routes and named `can:` permission middleware on several sensitive admin endpoints; some user financial actions also use request throttles. Presence of middleware is not proof that every route and resource is correctly authorized. | Generate a complete route/role/permission matrix. Test anonymous, USER, SUPPORT, STAFF and ADMIN access; direct URL and HTTP-method variants; cross-user resource ownership; and server-side role/level enforcement. | Open |
| SEC-006 | High / verify | Payments and webhooks | Inspected payment funding and VTU webhook controllers reference signature verification and idempotency/duplicate-event controls. Full replay, concurrency and failure recovery have not been established by this source inspection alone. | Verify signature canonicalization, timestamp/replay handling, unique provider event/reference constraints, atomic ledger writes, idempotent retries, lock behavior, and safe recovery after timeout/crash. Use test credentials and sandbox/fakes only. | Open |
| SEC-007 | High / verify | Passkeys and account security | Passkey management controller requires current-password confirmation and limits the confirmation window to five minutes. The complete WebAuthn challenge, origin, RP ID, credential ownership, expiry and replay behavior require review in the actual registration/authentication flow. | Audit the end-to-end WebAuthn implementation and test HTTPS origin validation, single-use challenge, expiry, signature/counter handling where applicable, user binding, session rotation, recovery and rate limits. | Open |
| SEC-008 | Medium / verify | File uploads | Branding asset controller validates uploaded images with MIME/extension rules and size limits. Storage destination, generated filenames, SVG handling, server execution policy and public access need end-to-end verification. | Confirm images are safely re-encoded where appropriate, filenames are generated, upload storage cannot execute scripts, SVG is rejected or safely sanitized, and authorization/CSRF controls protect upload routes. | Open |
| SEC-009 | Medium / verify | Injection and unsafe primitives | Initial GitHub code searches did not surface matches for several searched dangerous function patterns; this is not exhaustive proof that injection or unsafe deserialization is absent. | Perform broader code and dependency scans for raw SQL, shell execution, unsafe deserialization, unsafe redirects, SSRF, XSS, mass assignment and path traversal; validate findings manually and add regression tests. | Open |
| SEC-010 | Medium / verify | CI security controls | Existing Phase 1 workflow runs Laravel tests and a frontend build; the inspected workflow uses `composer install` and `npm install`. A passing suite does not replace dependency scans or production runtime verification. | Add/verify lockfile-enforced installation, Composer/npm vulnerability checks, static analysis where practical, secret scanning and security regression tests. Confirm supported PHP matrix and full-suite outcomes for the exact tested SHA. | Open |

## Verification plan

1. Obtain hosting-provider confirmation for SEC-001 and SEC-004; do not infer live state from repository configuration.
2. Review the log and Git history for SEC-002. The current-tree deletion does not erase earlier Git history or existing clones/caches.
3. Complete the route/permission matrix and account-security tests (SEC-005, SEC-007).
4. Run payment, webhook, wallet, refund, payout and recovery tests under concurrency and injected failures (SEC-006).
5. Complete upload, injection, dependency, secret and API checks (SEC-003, SEC-008, SEC-009, SEC-010).
6. Record each fix with commit SHA, test command/workflow URL, result, and remaining limitation. Do not mark the audit complete until critical findings are closed or the service is safely isolated and the residual risk is explicitly accepted.

## Safety constraints

- Never run exploit payloads against production or third-party systems.
- Do not run live deposits, transfers, refunds or payouts during security testing.
- Preserve production records; no database resets, truncations or destructive schema changes.
- Keep all repository work on `main` only.
- Redact credentials, OTPs, tokens and customer data from logs and reports.


## Current reconciliation update — 2026-10-22

- Latest executable-code SHA with terminal-green full PHP 8.3/8.4, frontend, SQLite/MariaDB security and asset workflows is `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`: [Laravel/frontend](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792095), [security](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792012), [asset publication](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792188). A commit comparison through the latest documentation update shows no later executable application-code changes.
- The root application log is absent from the current tree and ignored. SEC-002 remains open until historical exposure is reviewed; deletion from the current tree does not erase earlier Git history or copies.
- SEC-003 remains open: repository checks did not find root `composer.lock` or `package-lock.json`; lockfile policy, deterministic installs and dependency vulnerability scans still need resolution.
- SEC-005 and SEC-006 remain open pending exhaustive route/method/role ownership tests and complete webhook/payment recovery acceptance. Existing financial source review and concurrency tests are evidence but not a substitute for that matrix.
- SEC-007 remains open for the end-to-end WebAuthn registration/assertion ceremony; controller origin/confirmation tests are not the complete ceremony.
- SEC-001 and SEC-004 remain hosting-evidence gates: confirm actual web PHP, CLI/cron/worker runtimes and patch/backport status; production environment, HTTPS, cookie flags, filesystem permissions and web-root exposure.
- SEC-008 through SEC-010 remain open for upload-path verification, broader injection/secret scans, lockfile-enforced install and dependency/static analysis.
- Detailed financial and authorization findings are in `docs/security/phase-1-financial-authorization-audit.md`. Phase 1 remains OPEN; no live transactions were performed and no deployment-only check is marked passed.
