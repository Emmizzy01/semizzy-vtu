<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Security\TotpTwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    public function show(Request $request, TotpTwoFactorService $totp): Response
    {
        $user = $request->user();
        $enabled = $user->two_factor_enabled_at !== null;
        $secret = $enabled ? null : $request->session()->get('security.totp_setup_secret');
        if (!$enabled && (!is_string($secret) || $secret === '')) {
            $secret = $totp->newSecret();
            $request->session()->put('security.totp_setup_secret', $secret);
        }
        $recoveryCodes = $enabled ? $request->session()->pull('security.totp_recovery_codes') : null;

        return Inertia::render('Security/TwoFactor', [
            'enabled' => $enabled,
            'enabledAt' => $user->two_factor_enabled_at?->toIso8601String(),
            'setupSecret' => $secret,
            'provisioningUri' => is_string($secret) ? $totp->provisioningUri($user, $secret) : null,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }

    public function enable(Request $request, TotpTwoFactorService $totp): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $secret = $request->session()->get('security.totp_setup_secret');
        if (!is_string($secret) || !$totp->verifySecret($secret, $data['code'])) {
            return back()->withErrors(['code' => 'The authenticator code is invalid. Check the device time and try again.']);
        }

        $codes = $totp->enable($request->user(), $secret);
        $request->session()->forget('security.totp_setup_secret');
        $request->session()->put('security.totp_recovery_codes', $codes);
        return redirect('/security/two-factor')->with('status', 'two-factor-enabled');
    }

    public function disable(Request $request, TotpTwoFactorService $totp): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $user = $request->user();
        if (!Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Your current password is incorrect.']);
        }
        if (!$totp->verifyUserCode($user, $data['code'])) {
            return back()->withErrors(['code' => 'The authenticator or unused recovery code is invalid.']);
        }

        $totp->disable($user);
        $request->session()->forget(['security.totp_setup_secret', 'security.totp_recovery_codes']);
        return redirect('/security/two-factor')->with('status', 'two-factor-disabled');
    }
}
