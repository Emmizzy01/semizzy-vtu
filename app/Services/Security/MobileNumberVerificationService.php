<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class MobileNumberVerificationService
{
    public function start(User $user): void
    {
        if (! config('auth_security.sms_otp_enabled', false)) {
            throw new RuntimeException('SMS phone verification is not enabled.');
        }

        $challenge = DB::transaction(function () use ($user): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $number = (string) $lockedUser->mobile_number;
            if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $number)) {
                throw new RuntimeException('Enter a mobile number in international format before requesting verification.');
            }

            if ($lockedUser->mobile_verified_at !== null
                && DB::table('verified_mobile_numbers')->where('user_id', $lockedUser->id)->where('mobile_number', $number)->exists()) {
                throw new RuntimeException('This mobile number is already verified.');
            }

            $id = (string) Str::uuid();
            $code = (string) random_int(100000, 999999);
            $ttl = max(1, min(30, (int) config('auth_security.email_otp_ttl_minutes', 10)));

            DB::table('mobile_verification_challenges')->where('user_id', $lockedUser->id)->whereNull('consumed_at')->delete();
            DB::table('mobile_verification_challenges')->insert([
                'id' => $id,
                'user_id' => $lockedUser->id,
                'mobile_number' => $number,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes($ttl),
                'attempts' => 0,
                'consumed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'number' => $number, 'code' => $code];
        }, attempts: 3);

        try {
            app(LoginOtpDeliveryService::class)->deliverMobileVerification($challenge['number'], $challenge['code']);
        } catch (\Throwable $exception) {
            DB::table('mobile_verification_challenges')->where('id', $challenge['id'])->delete();
            throw $exception;
        }
    }

    public function verify(User $user, string $code): string
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return 'invalid';
        }

        try {
            return DB::transaction(function () use ($user, $code): string {
                $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $number = (string) $lockedUser->mobile_number;
                if ($number === '') {
                    return 'missing';
                }

                $challenge = DB::table('mobile_verification_challenges')
                    ->where('user_id', $lockedUser->id)
                    ->where('mobile_number', $number)
                    ->whereNull('consumed_at')
                    ->orderByDesc('created_at')
                    ->lockForUpdate()
                    ->first();

                if (! $challenge) {
                    return 'missing';
                }

                if (now()->greaterThanOrEqualTo($challenge->expires_at)) {
                    DB::table('mobile_verification_challenges')->where('id', $challenge->id)
                        ->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
                    return 'expired';
                }

                $maxAttempts = max(1, min(10, (int) config('auth_security.email_otp_max_attempts', 5)));
                if ((int) $challenge->attempts >= $maxAttempts) {
                    return 'locked';
                }

                if (! Hash::check($code, $challenge->code_hash)) {
                    $attempts = (int) $challenge->attempts + 1;
                    DB::table('mobile_verification_challenges')->where('id', $challenge->id)->update([
                        'attempts' => $attempts,
                        'updated_at' => now(),
                    ]);
                    return $attempts >= $maxAttempts ? 'locked' : 'invalid';
                }

                $claim = DB::table('verified_mobile_numbers')->where('mobile_number', $number)->lockForUpdate()->first();
                if ($claim && (int) $claim->user_id !== (int) $lockedUser->id) {
                    DB::table('mobile_verification_challenges')->where('id', $challenge->id)
                        ->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
                    return 'duplicate';
                }

                DB::table('verified_mobile_numbers')->where('user_id', $lockedUser->id)->delete();
                DB::table('verified_mobile_numbers')->insert([
                    'user_id' => $lockedUser->id,
                    'mobile_number' => $number,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('mobile_verification_challenges')->where('id', $challenge->id)->whereNull('consumed_at')
                    ->update(['consumed_at' => now(), 'updated_at' => now()]);
                $lockedUser->forceFill(['mobile_verified_at' => now()])->save();

                return 'verified';
            }, attempts: 3);
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'unique') || str_contains($message, 'duplicate')) {
                return 'duplicate';
            }
            throw $exception;
        }
    }
}
