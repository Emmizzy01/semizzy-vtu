<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TransactionPinService;
use App\Services\Security\UserSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
                'avatar_url' => $user->avatar_path ? Storage::disk('public')->url($user->avatar_path) : null,
                'address' => $user->address,
                'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
                'sex' => $user->sex,
                'mobile_number' => $user->mobile_number,
                'mobile_verified' => $user->mobile_verified_at !== null
                    && DB::table('verified_mobile_numbers')->where('user_id', $user->id)->where('mobile_number', $user->mobile_number)->exists(),
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
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'sex' => ['nullable', 'in:male,female,other,prefer_not_to_say'],
            'mobile_number' => ['nullable', 'regex:/^\\+[1-9][0-9]{7,14}$/'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);
        $oldName = $user->name;
        $name = trim($data['name']);
        $mobileNumber = isset($data['mobile_number']) ? trim((string) $data['mobile_number']) : null;
        $mobileNumber = $mobileNumber === '' ? null : $mobileNumber;
        $mobileChanged = $mobileNumber !== $user->mobile_number;
        $updates = [
            'name' => $name,
            'address' => isset($data['address']) ? trim($data['address']) : null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'sex' => $data['sex'] ?? null,
            'mobile_number' => $mobileNumber,
        ];
        if ($mobileChanged) {
            $updates['mobile_verified_at'] = null;
        }
        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars/'.$user->id, 'public');
            if ($user->avatar_path) Storage::disk('public')->delete($user->avatar_path);
            $updates['avatar_path'] = $path;
        }
        DB::transaction(function () use ($user, $updates, $mobileChanged): void {
            if ($mobileChanged) {
                DB::table('verified_mobile_numbers')->where('user_id', $user->id)->delete();
                DB::table('mobile_verification_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->delete();
            }
            $user->forceFill($updates)->save();
        });
        if ($name !== $oldName) $security->record($user->id, 'profile_name_updated', 'success', ['changed' => true]);
        $security->record($user->id, 'profile_details_updated', 'success', [
            'address_changed' => array_key_exists('address', $data),
            'date_of_birth_changed' => array_key_exists('date_of_birth', $data),
            'sex_changed' => array_key_exists('sex', $data),
            'mobile_number_changed' => $mobileChanged,
            'avatar_changed' => $request->hasFile('avatar'),
        ]);
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

        $previousSessionHash = $sessions->currentHash($request);
        $result = DB::transaction(function () use ($user, $data, $security, $sessions, $previousSessionHash): string {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // Recheck against the locked row so two simultaneous password changes
            // cannot both succeed using the same old password.
            if (! Hash::check($data['current_password'], $lockedUser->password)) {
                $security->record($lockedUser->id, 'password_change', 'failed', []);
                return 'incorrect';
            }

            if (Hash::check($data['password'], $lockedUser->password)) {
                return 'same';
            }

            $sessions->revokeOthers($lockedUser, $previousSessionHash);
            $lockedUser->forceFill([
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
            ])->save();

            // Invalidate any OTP sign-in that began before this password change.
            DB::table('login_challenges')->where('user_id', $lockedUser->id)->delete();
            $security->record($lockedUser->id, 'password_changed', 'success', [
                'other_sessions_revoked' => true,
                'pending_login_challenges_invalidated' => true,
            ]);

            return 'changed';
        }, attempts: 3);

        if ($result === 'incorrect') {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }
        if ($result === 'same') {
            return back()->withErrors(['password' => 'Choose a new password different from your current password.']);
        }

        $user->refresh();
        $request->session()->regenerate();
        $sessions->rotateCurrent($request, $user, $previousSessionHash);

        return back()->with('status', 'Password changed. Your current session has been refreshed; sign in again on other devices if prompted.');
    }
}
