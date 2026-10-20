# WebAuthn / passkey foundation

SEMIZZY uses Laravel's laravel/passkeys server package and the matching @laravel/passkeys browser client for standards-based WebAuthn registration and assertions. The server package validates WebAuthn ceremony data; SEMIZZY does not implement credential signature verification itself.

## Safety boundaries

- PASSKEYS_ENABLED defaults to false. The feature middleware returns 404 while disabled and refuses non-HTTPS origins or missing relying-party configuration.
- PASSKEYS_RELYING_PARTY_ID must be the production hostname or its approved parent domain. PASSKEYS_ALLOWED_ORIGINS must be a comma-separated list of exact HTTPS origins (scheme + host + optional port), with no wildcard origins.
- PASSKEYS_USER_HANDLE_SECRET should be a stable secret. If omitted, the Laravel application key is used; rotating it can make existing credentials unusable.
- Registration/deletion routes require a password confirmation timestamp no older than five minutes. The confirmation endpoint verifies the current password server-side.
- Login authorization rejects inactive accounts and refuses passkey login while separately configured email/SMS/WhatsApp OTP or TOTP is required, so passkeys do not silently bypass existing step-up verification.
- Registration, successful verification and deletion events write safe event type/outcome/passkey ID entries to the existing transaction security audit table; credential payloads and secrets are not logged.
- Browser passkeys use device/platform authenticators when available. User verification is required by the server library's registration options; biometric prompts depend on the user's device and OS and are not guaranteed on every authenticator.
- Passkeys are unavailable until Composer/npm dependencies are installed, the migration is applied, production HTTPS/RP/origin values are configured, and real-browser registration/login tests pass.

## Deployment

1. Install Composer and npm dependencies using the updated manifests.
2. Apply the additive create_passkeys_table migration; do not reset the database.
3. Configure APP_URL=https://your-real-hostname, PASSKEYS_RELYING_PARTY_ID, exact PASSKEYS_ALLOWED_ORIGINS, and a stable PASSKEYS_USER_HANDLE_SECRET.
4. Test enrollment, assertion, invalid origin/challenge/signature rejection, deleted credentials, inactive users, session rotation, OTP/TOTP step-up blocking and recovery paths.
5. Enable PASSKEYS_ENABLED=true only after those checks and production HTTPS verification.

No provider messages, payment, payout, refund or service purchase is part of passkey setup.
