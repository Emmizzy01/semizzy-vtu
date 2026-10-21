<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TransactionPinService;
use App\Services\Security\UserSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    public function requestForm(): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => session('status')]);
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink($request->only('email'));

        // Do not reveal whether an address is registered.
        return back()->with('status', 'If an account exists for that email, a password reset link has been sent.');
    }

    public function resetForm(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => strtolower(trim((string) $request->query('email', ''))),
            'status' => session('status'),
        ]);
    }

    public function reset(Request $request, TransactionPinService $security, UserSessionService $sessions): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) use ($security, $sessions): void {
            DB::transaction(function () use ($user, $password, $security, $sessions): void {
                $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $lockedUser->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    // A forgotten login password invalidates the old transaction PIN.
                    'transaction_pin_hash' => null,
                    'transaction_pin_failed_attempts' => 0,
                    'transaction_pin_locked_until' => null,
                ])->save();

                // A pre-reset password + OTP flow must not complete after the reset.
                DB::table('login_challenges')->where('user_id', $lockedUser->id)->delete();
                $sessions->revokeAll($lockedUser);
                $security->record($lockedUser->id, 'password_reset_completed', 'success', [
                    'transaction_pin_cleared' => true,
                    'sessions_revoked' => true,
                    'pending_login_challenges_invalidated' => true,
                ]);
            });
        });

        if ($status === Password::PASSWORD_RESET) {
            return redirect('/login')->with('status', 'Password reset successfully. Set a new transaction PIN after signing in before making wallet-funded purchases.');
        }

        return back()->withErrors(['email' => 'This password reset link is invalid or expired. Request a new link and try again.'])->withInput($request->only('email'));
    }
}
