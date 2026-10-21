# Authenticator-based two-factor authentication

## Scope

The core supports optional TOTP authenticator verification after password sign-in and, when enabled, email login OTP. It supports six-digit, 30-second TOTP codes and ten single-use recovery codes. The authenticator secret is encrypted at rest; recovery codes are stored as hashes and shown once after enabling.

## User flow

1. Sign in and open **Two-factor security**.
2. Add the displayed secret to a TOTP-compatible authenticator app.
3. Confirm setup with a current six-digit code and the current login password.
4. Save the ten recovery codes in a safe place.
5. On future sign-in, enter a TOTP code or one unused recovery code after password and any configured email OTP.
6. Disabling requires the current password and a valid authenticator or unused recovery code.

## Security controls

- Enable/disable and recovery-code replacement happen in one database transaction with the user row locked, so partial database failure cannot leave the enabled state and recovery-code set inconsistent.
- Pending TOTP login challenges expire after five minutes, are limited to five failed attempts, and are bound to a fingerprint of the password hash so a password change/reset invalidates the pending challenge.
- Recovery codes are single-use and consumed under a database transaction/row lock.
- The controller rejects attempts to re-enable TOTP over an already configured authenticator; users must disable it deliberately first.
- Passkey login is denied while TOTP or the configured login OTP step-up is required.

## Verification and deployment

- The 2026-10-21 CI sweep passed the SQLite full suite and focused MariaDB suite, including atomic enable/disable rollback coverage, single-use recovery codes, failed-attempt expiry, five-minute challenge expiry, email-OTP chaining, and password-reset invalidation. PHP 8.3 and PHP 8.4 full suites also passed on the same code commit.
- Apply the additive migration through the normal reviewed deployment process; do not reset production data.
- Keep APP_KEY stable and protected. Configure production HTTPS, secure session cookies, rate limiting, and working email transport if email OTP is enabled.
- Users who lose both their authenticator and recovery codes require a separately authorized support recovery process; staff must not read or generate a user's existing codes.
- Automated tests do not replace production migration verification or a reviewed support-recovery process.
