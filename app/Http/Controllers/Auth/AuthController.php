<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TransactionPinService;
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

    public function login(Request $request): RedirectResponse
    {
        // Match registration's normalization so accidental surrounding spaces or
        // email-address casing do not prevent an otherwise valid sign-in.
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        if (! Auth::attempt([
            'email' => strtolower($credentials['email']),
            'password' => $credentials['password'],
            'account_status' => 'active',
        ], (bool) ($credentials['remember'] ?? false))) {
            return back()->withErrors([
                'email' => 'These credentials do not match our records.',
            ])->onlyInput('email');
        }

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
            'transaction_pin' => ['nullable', 'string', 'digits:6', 'regex:/^(?!([0-9])\1{5})\d{6}$/'],
            'transaction_pin_confirmation' => ['required_with:transaction_pin', 'nullable', 'string', 'same:transaction_pin'],
        ]);

        $pin = $validated['transaction_pin'] ?? null;
        if ($pin !== null && in_array($pin, [
            '012345', '123456', '234567', '345678', '456789',
            '987654', '876543', '765432', '654321', '543210',
            '123123', '121212', '112233',
        ], true)) {
            return back()->withErrors(['transaction_pin' => 'Choose a less predictable six-digit transaction PIN.'])->withInput($request->except(['password', 'password_confirmation', 'transaction_pin', 'transaction_pin_confirmation']));
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
