# SEMIZZY ONE — Phase 1 Implementation Completion Register

This register tracks implementation against `docs/SEMIZZY-BLUEPRINT.md` and the owner-approved core requirements. It is not a test report. **No item is accepted solely because a file or route exists**; final acceptance happens after implementation is complete and the planned test sweep is run.

## Delivery order

1. Finish missing end-to-end implementation while preserving current features and theme.
2. Review migrations, server-side authorization, provider failure handling, audit records, and deployment notes.
3. Run the complete PHP suites, MariaDB concurrency suites, and frontend production build only after the implementation sweep.
4. Fix failures without weakening safety gates; then report evidence and remaining deployment-only blockers.

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
