<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TransactionPinService;
use App\Services\Security\LoginChallengeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function loginForm(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request, LoginChallengeService $challenges): RedirectResponse
    {
        $identifier = trim((string) $request->input('identifier', $request->input('email', '')));
        $request->merge(['identifier' => $identifier]);

        $credentials = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $identifier = $credentials['identifier'];
        $userQuery = User::query();

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $userQuery->whereRaw('LOWER(email) = ?', [strtolower($identifier)]);
        } elseif (preg_match('/^[+0-9() .-]{7,32}$/', $identifier)) {
            // A WhatsApp number is accepted only after its ownership has been verified.
            $normalizedNumber = preg_replace('/[() .-]/', '', $identifier);
            $userQuery->where('whatsapp_number', $normalizedNumber)
                ->whereNotNull('whatsapp_verified_at');
        } else {
            $userQuery->whereRaw('LOWER(username) = ?', [strtolower($identifier)]);
        }

        $user = $userQuery->where('account_status', 'active')->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors([
                'identifier' => 'These credentials do not match our records.',
            ])->onlyInput('identifier');
        }

        if (config('auth_security.email_otp_enabled', false)) {
            if (! $user->email) {
                return back()->withErrors(['identifier' => 'This account cannot use email verification.'])->onlyInput('identifier');
            }

            try {
                $challenges->start($request, $user, (bool) ($credentials['remember'] ?? false));
            } catch (\Throwable $exception) {
                report($exception);

                return back()->withErrors(['identifier' => 'We could not send a verification code. Please try again later.'])->onlyInput('identifier');
            }

            return redirect()->route('login.challenge');
        }

        Auth::login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();

        // Preserve Laravel's intended destination. Otherwise, /dashboard routes
        // customers to their dashboard and permissioned staff to the command center.
        return redirect()->intended('/dashboard');
    }

    public function registerForm(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function register(Request $request, TransactionPinService $pins): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
            'transaction_pin' => ['nullable', 'string', 'digits:4', 'regex:/^(?!([0-9])\1{3})\d{4}$/'],
            'transaction_pin_confirmation' => ['required_with:transaction_pin', 'nullable', 'string', 'same:transaction_pin'],
        ]);

        $pin = $validated['transaction_pin'] ?? null;
        if ($pin !== null && in_array($pin, [
            '0123', '1234', '2345', '3456', '4567', '5678', '6789',
            '9876', '8765', '7654', '6543', '5432', '4321', '3210',
            '1212', '1122', '2211', '1010',
        ], true)) {
            return back()->withErrors(['transaction_pin' => 'Choose a less predictable four-digit transaction PIN.'])->withInput($request->except(['password', 'password_confirmation', 'transaction_pin', 'transaction_pin_confirmation']));
        }

        $user = DB::transaction(function () use ($validated, $pin, $pins): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'password' => $validated['password'],
            ]);

            if ($pin !== null) {
                $user->forceFill([
                    'transaction_pin_hash' => Hash::make($pin),
                    'transaction_pin_failed_attempts' => 0,
                    'transaction_pin_locked_until' => null,
                    'transaction_pin_changed_at' => now(),
                ])->save();

                $pins->record($user->id, 'pin_created', 'success', ['source' => 'registration']);
            }

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
