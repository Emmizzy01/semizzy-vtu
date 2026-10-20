# Authenticator-based two-factor authentication

## Scope

The core now supports optional TOTP authenticator verification after the user's password and, when enabled, email login OTP. It supports standard six-digit, 30-second TOTP codes and ten single-use recovery codes. The authenticator secret is encrypted at rest; recovery codes are stored as hashes and shown only once after enabling.

## User flow

1. Sign in and open **Two-factor security**.
2. Add the displayed secret to a TOTP-compatible authenticator app.
3. Confirm setup with a current six-digit code.
4. Save the ten recovery codes in a safe place.
5. On future sign-in, enter a TOTP code or one unused recovery code after password and any configured email OTP.
6. Disabling requires the current password and a valid authenticator or unused recovery code.

## Operations and release

- Apply the new migration through the normal reviewed deployment process; do not reset production data.
- Ensure APP_KEY is stable and protected. Laravel encryption is used to protect authenticator secrets.
- Configure production HTTPS, secure session cookies, rate limiting, and working email transport if email OTP is enabled.
- Users who lose both their authenticator and recovery codes require a separately authorized support recovery process; staff must not read or generate a user's existing codes.
- This implementation has not yet passed the requested final test sweep. Do not claim the feature is production-verified until tests cover TOTP correctness, invalid/expired codes, recovery-code single use, brute-force limits, email-OTP chaining, session fixation, and migration behavior on supported databases.
