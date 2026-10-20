<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class PasskeyManagementController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Security/Passkeys', [
            'enabled' => (bool) config('passkeys.enabled', false),
            'credentials' => ! config('passkeys.enabled', false) ? [] : $user->passkeys()->orderByDesc('last_used_at')->get(['id', 'name', 'last_used_at', 'created_at'])->map(fn ($passkey) => [
                'id' => $passkey->getKey(),
                'name' => $passkey->name,
                'last_used_at' => $passkey->last_used_at?->toISOString(),
                'created_at' => $passkey->created_at?->toISOString(),
            ])->values(),
            'recentlyConfirmed' => (int) $request->session()->get('passkeys.password_confirmed_at', 0) >= now()->subMinutes(5)->timestamp,
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
        ]);

        if (! Hash::check($data['current_password'], (string) $request->user()->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $request->session()->put('passkeys.password_confirmed_at', now()->timestamp);

        return back()->with('status', 'Password confirmed. You can manage passkeys for the next five minutes.');
    }
}
