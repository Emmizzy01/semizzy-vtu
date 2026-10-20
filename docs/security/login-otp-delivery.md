# Login OTP delivery channels

## Implemented in code

The login challenge flow can deliver the same hashed, expiring, single-use code through the configured AUTH_LOGIN_OTP_CHANNEL: email, sms, or whatsapp. Codes are persisted as hashes, have bounded lifetime and attempt counts, and are consumed once before session authentication. SMS delivery uses the user's mobile number; WhatsApp delivery requires the user's WhatsApp number to already be verified.

SMS and WhatsApp use a generic HTTPS JSON adapter configured with separate endpoints and bearer tokens. The adapter sends to, message, purpose=login_otp, and channel. This is an integration contract, not a claim that a specific vendor has been integrated. A real provider's API contract, templates, delivery semantics, credentials, and webhook/status behavior must be reviewed before enabling either channel.

## Configuration and safety

- Default channel is email; all OTP enforcement flags default to disabled to preserve existing login behavior until deliberately configured.
- Set AUTH_LOGIN_OTP_CHANNEL=sms or whatsapp only after configuring the corresponding endpoint/token and testing the real provider.
- AUTH_LOGIN_SMS_OTP_ENABLED and AUTH_LOGIN_WHATSAPP_OTP_ENABLED default to false; missing credentials, non-HTTPS endpoints, disabled channels, or failed HTTP responses fail closed.
- Use HTTPS in production, keep tokens out of source control, and configure request throttling at the application and provider levels.
- The generic adapter does not prove delivery to the handset. Provider-specific acceptance receipts, retries and delivery-status verification are still required before production acceptance.
- Existing email OTP behavior remains supported. This change does not send a real SMS/WhatsApp message during development or tests.

## Biometric/passkey status

Device biometrics should be implemented using standards-based WebAuthn/passkeys. TOTP authenticator codes are not biometrics, and browser fingerprints are not authentication. The repository does not currently include a WebAuthn server verification library, so passkey registration/assertion must remain a tracked implementation gap until server-side challenge, origin/RP ID, credential signature, counter and recovery verification are added and tested.
