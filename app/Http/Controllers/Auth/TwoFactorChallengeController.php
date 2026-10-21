<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TotpTwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $userId = $request->session()->get('auth.two_factor_user_id');
        $startedAt = $request->session()->get('auth.two_factor_started_at');
        $passwordFingerprint = $request->session()->get('auth.two_factor_password_fingerprint');

        if (!is_int($userId) || !is_int($startedAt) || !is_string($passwordFingerprint)) {
            $this->clearPendingChallenge($request);
            return redirect()->route('login')->withErrors(['identifier' => 'Please sign in again.']);
        }
        if ($startedAt <= now()->subMinutes(5)->timestamp) {
            $this->clearPendingChallenge($request);
            return redirect()->route('login')->withErrors(['identifier' => 'The two-factor challenge expired. Please sign in again.']);
        }

        $user = User::query()->whereKey($userId)->where('account_status', 'active')->first();
        if (!$user || !hash_equals($passwordFingerprint, hash('sha256', (string) $user->password))) {
            $this->clearPendingChallenge($request);
            return redirect()->route('login')->withErrors(['identifier' => 'Your credentials changed. Please sign in again.']);
        }

        return Inertia::render('Auth/VerifyTwoFactor');
    }

    public function verify(Request $request, TotpTwoFactorService $totp): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $userId = $request->session()->get('auth.two_factor_user_id');
        $startedAt = $request->session()->get('auth.two_factor_started_at');
        $passwordFingerprint = $request->session()->get('auth.two_factor_password_fingerprint');

        if (!is_int($userId) || !is_int($startedAt) || !is_string($passwordFingerprint)) {
            $this->clearPendingChallenge($request);
            return redirect()->route('login')->withErrors(['identifier' => 'Please sign in again.']);
        }
        if ($startedAt <= now()->subMinutes(5)->timestamp) {
            $this->clearPendingChallenge($request);
            return redirect()->route('login')->withErrors(['identifier' => 'The two-factor challenge expired. Please sign in again.']);
        }

        $user = User::query()->whereKey($userId)->where('account_status', 'active')->first();
        if (!$user || !hash_equals($passwordFingerprint, hash('sha256', (string) $user->password))) {
            $this->clearPendingChallenge($request);
            return redirect()->route('login')->withErrors(['identifier' => 'Your credentials changed. Please sign in again.']);
        }

        if (!$totp->verifyUserCode($user, strtoupper(trim($data['code'])))) {
            $attempts = (int) $request->session()->get('auth.two_factor_attempts', 0) + 1;
            $request->session()->put('auth.two_factor_attempts', $attempts);
            if ($attempts >= 5) {
                $this->clearPendingChallenge($request);
                return redirect()->route('login')->withErrors(['identifier' => 'Too many verification failures. Please sign in again.']);
            }
            return back()->withErrors(['code' => 'The authenticator or recovery code is invalid.']);
        }

        $remember = (bool) $request->session()->get('auth.two_factor_remember', false);
        $this->clearPendingChallenge($request);
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    private function clearPendingChallenge(Request $request): void
    {
        $request->session()->forget([
            'auth.two_factor_user_id',
            'auth.two_factor_remember',
            'auth.two_factor_attempts',
            'auth.two_factor_started_at',
            'auth.two_factor_password_fingerprint',
        ]);
    }
}
