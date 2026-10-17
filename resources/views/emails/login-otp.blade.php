<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="font-family:Arial,sans-serif;color:#172033;line-height:1.6">
    <h1 style="font-size:22px">Confirm your SEMIZZY sign-in</h1>
    <p>Use this one-time code to finish signing in:</p>
    <p style="font-size:30px;font-weight:700;letter-spacing:8px">{{ $code }}</p>
    <p>This code expires in {{ (int) config('auth_security.email_otp_ttl_minutes', 10) }} minutes and can only be used once.</p>
    <p>If you did not try to sign in, you can ignore this message and consider changing your password.</p>
    <p>SEMIZZY Security</p>
</body>
</html>
