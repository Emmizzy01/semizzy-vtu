<?php

namespace App\Providers;

use App\Models\User;
use App\Models\UserPermission;
use App\Services\IntegrationSettings;
use App\Services\Education\Providers\DatawayEducationProvider;
use App\Services\Education\Providers\GsubzEducationProvider;
use App\Services\Education\Providers\SogoEducationProvider;
use App\Services\Education\Providers\VtpassEducationProvider;
use App\Services\Vtu\DatawayProviderClient;
use App\Services\Vtu\GsubzProviderClient;
use App\Services\Vtu\MultiProviderVtuRouter;
use App\Services\Vtu\SogoProviderClient;
use App\Services\Vtu\VtpassProviderClient;
use App\Services\Vtu\VtuProvider;
use App\Services\Vtu\VtuProviderClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Contracts\PasskeyUser;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('education.providers.sogo', SogoEducationProvider::class);
        $this->app->bind('education.providers.gsubz', GsubzEducationProvider::class);
        $this->app->bind('education.providers.vtpass', VtpassEducationProvider::class);
        $this->app->bind('education.providers.dataway', DatawayEducationProvider::class);

        $this->app->bind(VtuProvider::class, function ($app): VtuProvider {
            return new MultiProviderVtuRouter(
                $app->make(VtuProviderClient::class),
                $app->make(VtpassProviderClient::class),
                $app->make(IntegrationSettings::class),
                $app->make(SogoProviderClient::class),
                $app->make(GsubzProviderClient::class),
                $app->make(DatawayProviderClient::class),
            );
        });
    }

    public function boot(): void
    {
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
