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
