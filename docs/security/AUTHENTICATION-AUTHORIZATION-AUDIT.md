# SEMIZZY ONE — Authentication and Authorization Audit (Phase 1)

**Repository:** `Emmizzy01/semizzy-vtu`  
**Branch:** `main` only  
**Review date:** 2026-10-22  
**Status:** Source review and existing-test inventory in progress. This is not a penetration-test certificate or production sign-off.

## Scope reviewed

- `routes/web.php`
- `AuthController`, `LoginChallengeController`, `TwoFactorChallengeController`, `PasswordResetController`
- `PasskeyManagementController`, `EnsurePasskeysEnabled`, `EnsureRecentPasskeyConfirmation`
- `UserSessionController`, `UserSessionService`, `LoginChallengeService`
- Existing feature tests: `AdminDirectUrlAuthorizationTest`, `LoginChallengeTest`, `PasskeySecurityFoundationTest`, `PasskeyOriginAndConfirmationRegressionTest`, `TwoFactorAtomicityTest`, `TwoFactorAuthenticationTest`, `PasswordResetWorkflowTest`, `UserSessionManagementTest`, `AuthFlowTest`, `FinancialMutationAuthenticationTest`.

## Existing positive controls evidenced in source/tests

1. **Login:** POST /login has throttling; credentials are checked against active accounts; email and username comparisons are case-insensitive; phone login requires verified ownership and refuses ambiguous verified matches.
2. **Login OTP:** OTPs are stored as hashes, expire, have a bounded attempt count, are consumed under a database row lock, and pre-auth sessions are regenerated before being bound to a challenge. Tests cover one-time use, invalid attempts, expiry and a fail-closed mail configuration.
3. **TOTP 2FA:** pending challenge has a five-minute lifetime and is tied to a password fingerprint; attempts are bounded in session state. Existing tests cover atomic secret/recovery-code persistence and disable cleanup.
4. **Password recovery:** response does not disclose whether an email exists; reset requires a valid token and strong password; password reset invalidates pending login challenges, clears the old transaction PIN, rotates remember token and revokes tracked sessions.
5. **Session management:** session identifiers are hashed in the tracking table; revocation is scoped to the authenticated user; revoked sessions are checked on later authenticated requests. Password-change/revocation coverage exists.
6. **Passkey management foundation:** passkeys are disabled by default; enabled origins must be HTTPS, exact, non-wildcard subdomains of the relying-party domain; management actions require recent password confirmation bound to the current password. Existing regression tests cover wildcard/HTTP origins and stale confirmation.
7. **Role boundary tests:** existing direct-URL tests exercise guest redirects and ADMIN, STAFF, SUPPORT, USER permission combinations for dashboard/refunds routes; suspended staff is logged out.

## Additional source-level findings from the next pass

- The staff permission-management controller explicitly rejects self-targeting, permits only the fixed `refunds.approve` permission, requires a reason, records actor/target/reason audit events, and performs the grant/revoke mutation transactionally. Existing `StaffAccessManagementTest` and `StaffPermissionAuditTest` cover these controls. Remaining concern is exhaustive route/method and privilege-boundary coverage, not an observed self-grant bypass in this controller.
- `config/passkeys.php` configures the package's management middleware as `auth + EnsurePasskeysEnabled + EnsureRecentPasskeyConfirmation`, and its general passkey middleware is `web + EnsurePasskeysEnabled`. The browser uses the Laravel Passkeys package; package-resolved route middleware and the vendor's full ceremony implementation still need verification from the installed locked dependency and runtime route list. No claim is made that repository middleware alone proves WebAuthn signature/challenge safety.
- KYC evidence is stored on the private local disk, access is routed through a staff-capability-protected review endpoint, access is audited, and tests cover customer denial and the review-before-approval workflow.
- Added a password-reset token replay regression test in commit `60153b52729ed8fea31e403e514a56001086d19d`. It asserts that a token accepted once cannot change the password a second time. The test has been committed but is **not yet verified by a completed CI run**.

## Open verification items

| ID | Priority | Required evidence / test |
|---|---|---|
| AUTH-01 | High | Enumerate every named route and middleware stack; test guest, USER, SUPPORT, STAFF and ADMIN against every sensitive GET/POST/PUT/PATCH/DELETE endpoint, including direct URL and method substitution. Current direct-URL tests cover representative routes, not the entire route table. |
| AUTH-02 | High | Verify every resource-bound controller enforces ownership (orders, transfers, payouts, deposits, KYC evidence, support tickets, beneficiaries, scheduled purchases, sessions and passkeys). Include cross-user ID/public-ID substitution tests. |
| AUTH-03 | High | Review staff permission grant/revoke implementation for privilege escalation: only authorized principals can grant allowed permissions, cannot grant their own privileges, and cannot alter protected ADMIN capability assignments. Test role downgrade/revocation immediately takes effect. |
| AUTH-04 | High | Audit the complete passkey ceremony implementation and package: registration/authentication challenge generation and single use, challenge expiry, origin and RP-ID verification, credential/user binding, signature verification, user verification policy, duplicate credential IDs, counter behavior where applicable, replay rejection, rate limits and session rotation. Management-controller checks alone do not establish the complete WebAuthn flow. |
| AUTH-05 | High | Test OTP concurrency and abuse limits across independent sessions/IPs, resend behavior, challenge replacement races, account status changes between password check and OTP verification, and delivery provider failures. Confirm provider configuration cannot silently downgrade a required OTP channel. |
| AUTH-06 | Medium | Verify password reset broker expiry/throttling configuration and test token reuse, email normalization, concurrent reset attempts, and invalidation of active sessions across the deployed session handler. |
| AUTH-07 | Medium | Test session fixation/rotation on all successful authentication paths (password-only, OTP, TOTP, passkey); verify cookie Secure, HttpOnly, SameSite and HTTPS settings in production, which cannot be proven by repository tests alone. |
| AUTH-08 | Medium | Verify account suspension/status changes invalidate active sessions promptly and apply consistently to every authenticated route/API/webhook-adjacent path. |
| AUTH-09 | Medium | Review CSRF protections for browser state-changing routes and ensure API/webhook exceptions are narrowly scoped and independently authenticated. |
| AUTH-10 | Medium | Run the complete authentication/security test group and full CI on the exact resulting commit; capture workflow URLs and SHA before marking any item closed. |

## Execution and safety rules

- Keep work on `main` only.
- Do not use production credentials, real OTP recipients, live financial operations, or exploit payloads.
- Do not reset or truncate any database. Use isolated test databases and mocked delivery/provider services.
- Record each confirmed vulnerability with affected route/file, reproducible non-destructive test, fix commit, and passing regression test.
- Do not mark controls verified solely because middleware or a test class exists; record the test result and exact commit.
- Host-only settings (live PHP handler, cookie flags, HTTPS, environment variables and web-root exposure) require hosting evidence.


## Current evidence reconciliation — 2026-10-22

- Latest executable-code SHA with terminal-green CI is `5e41c5fe0cdd3ce951e1ce48616e5fc5a696c933`: [PHP 8.3/8.4 full suites and frontend](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792095), [SQLite/focused MariaDB security suites](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792012), and [asset publication](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39742792188). Current later commits only update documentation, ignore the log filename, or remove the tracked root application log; compare evidence confirms no executable application-code changes after that SHA.
- Representative role tests exist in `AdminDirectUrlAuthorizationTest`; guest financial-route tests exist in `FinancialMutationAuthenticationTest`; refund, payout and selected ownership tests exist in their dedicated suites. These do not close AUTH-01 through AUTH-03 because they do not yet enumerate every named sensitive route/method, every role, all cross-user resource substitutions, and all privilege grant/revoke combinations.
- AUTH-04 remains open: repository tests for HTTPS origin allowlists and recent password confirmation do not prove a complete browser WebAuthn ceremony.
- AUTH-05 remains open for independent-session/IP OTP abuse/race cases and actual authorised vendor/device delivery. AUTH-06 through AUTH-09 still need their full test matrix and/or production hosting evidence.
- AUTH-10's CI portion is satisfied for the executable-code SHA above, but the audit as a whole remains open until the targeted route/auth tests and other findings are resolved. Any new executable code requires fresh CI on its exact SHA.
- Keep SMS/WhatsApp delivery and passkeys disabled until vendor/device and HTTPS browser acceptance are completed. Do not perform live financial operations or use production credentials during this audit.


## Execution update — 2026-10-22 (latest test additions)

- Added `LoginChallengeTest::test_new_login_challenge_replaces_the_previous_unconsumed_challenge` in commit `a737b35ced960ae14ba9cc917f8e7b5ab001bbe9`. It verifies sequential re-authentication removes the previous pending challenge, binds the session to the replacement challenge, and does not authenticate before code verification. This is a replacement regression test, **not a proof of cross-process concurrent race safety**.
- Added `UserAccountStatusTest::test_suspended_account_is_rejected_across_profile_wallet_and_session_routes` in commit `db895b5e876595996b93643ed62a9d5cb9760e70`. It checks that a suspended authenticated account is logged out when requesting profile, wallet transfer history, and session-management routes. CI has not yet reported results for these commits.
- Existing route coverage checks every registered `/admin` route has authentication and an explicit capability middleware. Existing role matrix tests still cover only representative admin dashboard/refund routes; they do **not** constitute the requested all-route × four-role × HTTP-method matrix.
- The repository tree does not contain `vendor/` or `composer.lock`, so the installed/resolved `laravel/passkeys` version and its actual vendor route middleware/ceremony code cannot be inspected from GitHub source alone. `config/passkeys.php` and project tests are not a substitute. Obtain the lockfile/vendor state from the actual build/deployment environment before closing AUTH-04.
- Latest executable test commits: `a737b35ced960ae14ba9cc917f8e7b5ab001bbe9` and `db895b5e876595996b93643ed62a9d5cb9760e70`. Run full CI against the final tip of `main` and record actual results. Do not close AUTH-01, AUTH-02, AUTH-04, AUTH-05, AUTH-07, or AUTH-10 based on these targeted additions alone.


## Execution update — 2026-10-22 (ownership, method and package-source pass)

- Added cross-user direct-URL support reply regression test in commit `dd253d41b0af407441291d9c9450253f5958e587`. It expects another customer’s ticket reply to return 404 and asserts no message/audit mutation.
- Added manual-deposit history isolation test in commit `0f7bfb339b3f0f47e217904a5f5a8be5a08e071f`. It submits separate test deposits for two users and asserts each user's listing excludes the other user's transfer reference.
- Added method-substitution checks in commit `3ed1ac8525c19da92706689ab26f73a3e778d296`: PATCH transfer history, DELETE manual-deposit collection and PUT service-beneficiary collection should be rejected as unsupported methods. These targeted assertions are pending CI and must be adjusted if actual framework route semantics differ.
- Upstream package sources were reviewed for context: Packagist lists `laravel/passkeys` v0.2.1 (published 2026-05-18) and requires `web-auth/webauthn-lib` 5.3.x; upstream route source places guest login-options/login routes behind configured package middleware, authenticated confirmation routes behind auth, and registration/deletion routes behind auth plus `management_middleware` and configured throttle where applicable. Upstream login controller logs the user in and regenerates the session after successful `VerifyPasskey`. See [Packagist package metadata](https://packagist.org/packages/laravel/passkeys), [upstream route definitions](https://github.com/laravel/passkeys-server/blob/main/routes/routes.php), and [upstream login controller](https://github.com/laravel/passkeys-server/blob/main/src/Http/Controllers/PasskeyLoginController.php).
- Important limitation: the repository still has no `composer.lock` or committed `vendor/` tree. The Composer constraint `^0.2.1` does not prove which exact package commit/version is installed on CI or cPanel. Upstream main source review is useful context only; it does not close the requirement to inspect the actual resolved package and run full registration/authentication ceremony tests.
- Still open: all-route × four-role authorization matrix; comprehensive cross-user tests for all financial, KYC, workspace, education-order, payout and beneficiary records; OTP true concurrent-worker races and provider failure modes; session fixation on every login method; actual WebAuthn expiry/replay/signature/credential-ownership tests; and CI results for the latest executable test commits.

## Latest route/CI reconciliation — 2026-10-22

- The current source `routes/web.php` was re-read after the passkey-test syntax fix. Sensitive customer routes are grouped under `auth`; admin route families use the explicit `auth` plus `can:staff.permissions.manage` group or narrower capability middleware, with the admin dashboard and refund approval paths separately guarded. This is a source-level observation, not a substitute for the resolved runtime route/middleware table.
- The file contains 155 route-declaration lines, including declarations nested under route groups. The complete runtime named-route list has **not** yet been exported and reconciled to HTTP methods, inherited middleware, controller-level authorization and tests. Therefore AUTH-01 remains open. AUTH-02 and AUTH-03 also remain open pending exhaustive cross-user substitution and role/permission grant/revoke coverage.
- Latest terminal-green evidence on executable SHA `318621c3641f93c0c2f9e9619f6f8b2c16a53326`: [PHP 8.3/8.4, frontend and MariaDB diagnostics](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39747957676), [SQLite and MariaDB security suites](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39747957614), and [frontend asset build/publication](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39747957707). All three workflows completed successfully on that exact SHA.
- Next audit work: export the actual Laravel route list in an isolated environment; generate the route × HTTP method × inherited middleware × role/capability matrix; match each resource-bound controller to foreign-owner tests; test staff permission changes and immediate revocation; then reconcile every acceptance criterion and each of the eight bulk actions against commit/test/deployment evidence.

**Status remains OPEN.** Passing CI establishes the tested commit's automated suite result; it does not certify the full authorization matrix, real WebAuthn ceremonies, live delivery providers, or production hosting configuration.
