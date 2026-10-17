<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TransactionPinService;
use App\Services\Security\UserSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProfileController extends Controller
{
    public function show(Request $request): InertiaResponse
    {
        /** @var User $user */
        $user = $request->user();
        return Inertia::render('Account/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type ?: 'customer',
                'user_level' => $user->user_level ?: 'level_1',
                'kyc_status' => $user->kyc_status ?: 'unverified',
                'transaction_pin_configured' => (bool) $user->transaction_pin_hash,
                'transaction_pin_changed_at' => $user->transaction_pin_changed_at?->toISOString(),
            ],
        ]);
    }

    public function update(Request $request, TransactionPinService $security): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);
        $oldName = $user->name;
        $name = trim($data['name']);
        if ($name !== $oldName) {
            $user->forceFill(['name' => $name])->save();
            $security->record($user->id, 'profile_name_updated', 'success', ['changed' => true]);
        }
        return back()->with('status', 'Profile saved.');
    }

    public function updatePassword(Request $request, TransactionPinService $security, UserSessionService $sessions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            $security->record($user->id, 'password_change', 'failed', []);
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }
        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Choose a new password different from your current password.']);
        }

        $sessions->revokeOthers($user, $sessions->currentHash($request));
        $user->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate();
        $security->record($user->id, 'password_changed', 'success', ['session_regenerated' => true, 'other_sessions_revoked' => true]);
        return back()->with('status', 'Password changed. Your current session has been refreshed; sign in again on other devices if prompted.');
    }
}
