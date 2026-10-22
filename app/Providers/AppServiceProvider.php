<?php

namespace App\Providers;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Events\PasskeyDeleted;
use App\Services\Security\TransactionPinService;
use Illuminate\Support\Facades\Event;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Service provider adapters are registered by their addon-owned provider.
    }

    public function boot(): void
    {
        Event::listen(PasskeyRegistered::class, function (PasskeyRegistered $event): void {
            if ($event->user instanceof User) app(TransactionPinService::class)->record((int) $event->user->getAuthIdentifier(), 'passkey_registered', 'success', ['passkey_id' => $event->passkey->getKey()]);
        });
        Event::listen(PasskeyVerified::class, function (PasskeyVerified $event): void {
            if ($event->user instanceof User) app(TransactionPinService::class)->record((int) $event->user->getAuthIdentifier(), 'passkey_verified', 'success', ['passkey_id' => $event->passkey->getKey()]);
        });
        Event::listen(PasskeyDeleted::class, function (PasskeyDeleted $event): void {
            if ($event->user instanceof User) app(TransactionPinService::class)->record((int) $event->user->getAuthIdentifier(), 'passkey_deleted', 'success', ['passkey_id' => $event->passkey->getKey()]);
        });

        Passkeys::authorizeLoginUsing(function (Request $request, PasskeyUser $passkeyUser, \Laravel\Passkeys\Passkey $passkey): bool {
            if (! $passkeyUser instanceof User || $passkeyUser->account_status !== 'active') {
                throw ValidationException::withMessages(['credential' => 'Passkey sign-in could not be completed.']);
            }

            // Never let passwordless sign-in bypass a separately configured OTP/TOTP step-up.
            $otpChannel = (string) config('auth_security.login_otp_channel', 'email');
            $otpRequired = match ($otpChannel) {
                'email' => (bool) config('auth_security.email_otp_enabled', false),
                'sms' => (bool) config('auth_security.sms_otp_enabled', false),
                'whatsapp' => (bool) config('auth_security.whatsapp_otp_enabled', false),
                default => false,
            };
            if ($otpRequired || $passkeyUser->two_factor_enabled_at) {
                throw ValidationException::withMessages(['credential' => 'Use password sign-in to complete your additional verification steps.']);
            }

            return true;
        });

        Gate::define('refunds.approve', fn (User $user): bool => UserPermission::query()
            ->where('user_id', $user->id)->where('permission', 'refunds.approve')->exists());

        Gate::define('staff.permissions.manage', fn (User $user): bool => UserPermission::query()
            ->where('user_id', $user->id)->where('permission', 'staff.permissions.manage')->exists());

        // Global dashboard/theme controls belong to staff managers only. Refund approval
        // remains a separate, narrow capability and must not imply platform administration.
        Gate::define('admin.dashboard', fn (User $user): bool => UserPermission::query()
            ->where('user_id', $user->id)->where('permission', 'staff.permissions.manage')->exists());
    }
}
