<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class LoginChallengeController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (! $this->loginOtpEnabled()) {
            return redirect()->route('login');
        }

        if (! $request->session()->has('auth.login_challenge_id')) {
            return redirect()->route('login')->withErrors(['identifier' => 'Please sign in again to request a new code.']);
        }

        return Inertia::render('Auth/VerifyLoginCode', ['channel' => (string) config('auth_security.login_otp_channel', 'email')]);
    }

    public function verify(Request $request): RedirectResponse
    {
        if (! $this->loginOtpEnabled()) {
            return redirect()->route('login');
        }

        $validated = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $challengeId = $request->session()->get('auth.login_challenge_id');

        if (! is_string($challengeId)) {
            return redirect()->route('login')->withErrors(['code' => 'This verification attempt has expired. Please sign in again.']);
        }

        $result = DB::transaction(function () use ($challengeId, $validated): array {
            $challenge = DB::table('login_challenges')->where('id', $challengeId)->lockForUpdate()->first();

            if (! $challenge || $challenge->consumed_at !== null || now()->greaterThanOrEqualTo($challenge->expires_at)) {
                return ['status' => 'expired'];
            }

            $maxAttempts = max(1, min(10, (int) config('auth_security.email_otp_max_attempts', 5)));
            if ((int) $challenge->attempts >= $maxAttempts) {
                return ['status' => 'locked'];
            }

            if (! Hash::check($validated['code'], $challenge->code_hash)) {
                DB::table('login_challenges')->where('id', $challengeId)->increment('attempts');
                return ['status' => 'invalid'];
            }

            DB::table('login_challenges')->where('id', $challengeId)->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'status' => 'ok',
                'user_id' => (int) $challenge->user_id,
                'remember' => (bool) $challenge->remember,
            ];
        });

        if ($result['status'] !== 'ok') {
            if (in_array($result['status'], ['expired', 'locked'], true)) {
                $request->session()->forget('auth.login_challenge_id');
                return redirect()->route('login')->withErrors(['identifier' => 'The verification code expired or the attempt limit was reached. Please sign in again.']);
            }

            return back()->withErrors(['code' => 'That code is invalid. Check it and try again.']);
        }

        $request->session()->forget('auth.login_challenge_id');
        $user = User::query()->whereKey($result['user_id'])->where('account_status', 'active')->first();

        if (! $user) {
            return redirect()->route('login')->withErrors(['identifier' => 'This account is not available for sign-in.']);
        }

        if ($user->two_factor_enabled_at !== null && is_string($user->two_factor_secret) && $user->two_factor_secret !== '') {
            $request->session()->regenerate();
            $request->session()->put('auth.two_factor_user_id', (int) $user->id);
            $request->session()->put('auth.two_factor_remember', (bool) $result['remember']);
            $request->session()->put('auth.two_factor_attempts', 0);
            return redirect()->route('login.two-factor');
        }

        Auth::login($user, $result['remember']);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    private function loginOtpEnabled(): bool
    {
        return match ((string) config('auth_security.login_otp_channel', 'email')) {
            'email' => (bool) config('auth_security.email_otp_enabled', false),
            'sms' => (bool) config('auth_security.sms_otp_enabled', false),
            'whatsapp' => (bool) config('auth_security.whatsapp_otp_enabled', false),
            default => false,
        };
    }

}
