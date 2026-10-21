# Login OTP delivery channels

## Implemented in code

The login challenge flow can deliver hashed, expiring, single-use codes through the configured channel: email, SMS, or WhatsApp. Challenges have bounded attempt counts and are consumed before session authentication. Password changes and password resets invalidate pending login challenges. When TOTP is enabled, a successful password/email-OTP step enters a five-minute TOTP challenge; a password fingerprint prevents that pending challenge from surviving a credential change.

SMS login delivery requires the user's mobile number to have a successful ownership verification and a matching unique row in verified_mobile_numbers. The profile workflow sends a six-digit verification code through the configured HTTPS SMS adapter; only a hashed, expiring, attempt-limited challenge is stored. A verified number is uniquely claimed, and changing the number clears its verification claim and any pending verification challenge. Unverified or ambiguous duplicate numbers cannot be used for phone login.

WhatsApp login delivery requires the user's WhatsApp number to already be verified. SMS and WhatsApp use a generic HTTPS JSON adapter configured with endpoint and bearer token. The adapter sends destination, message, purpose and channel. This is an integration contract, not a claim that a specific vendor has been integrated.

## Configuration and safety

- Email remains the default login channel; SMS and WhatsApp enforcement/delivery are disabled by default.
- Set AUTH_LOGIN_OTP_CHANNEL=sms or whatsapp only after configuring the corresponding endpoint/token and testing the real provider.
- Missing credentials, non-HTTPS endpoints, disabled channels, unverified SMS destinations, or failed HTTP responses fail closed.
- Use HTTPS in production, keep tokens out of source control, and configure application/provider throttling.
- The generic adapter does not prove handset delivery. Provider-specific request/response contracts, acceptance receipts, delivery-status handling, and recovery semantics must be reviewed before enabling SMS/WhatsApp in production.
- CI tests use mocked HTTP and do not send real SMS or WhatsApp messages. No provider is claimed live.

## Biometric/passkey status

Passkeys use standards-based WebAuthn. TOTP authenticator codes are not biometrics, and browser fingerprints are not authentication. The passkey server package and browser client are in the project, but the feature remains disabled until the production HTTPS/RP/origin configuration and real-browser registration/assertion/recovery ceremonies are verified. See docs/security/passkeys.md.
