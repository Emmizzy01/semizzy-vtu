<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginChallengeService
{
    public function start(Request $request, User $user, bool $remember): void
    {
        $challenge = $this->replacePendingChallenge($user, $remember);

        try {
            app(LoginOtpDeliveryService::class)->deliver($user, $challenge['code'], $challenge['channel']);
        } catch (\\Throwable $exception) {
            DB::table('login_challenges')->where('id', $challenge['id'])->delete();
            throw $exception;
        }

        // Rotate the pre-auth session before binding it to the challenge.
        $request->session()->regenerate();
        $request->session()->put('auth.login_challenge_id', $challenge['id']);
    }

    /**
     * Replace all pending challenges while serializing concurrent requests on the user row.
     *
     * @return array{id: string, code: string, channel: string}
     */
    public function replacePendingChallenge(User $user, bool $remember): array
    {
        $channel = (string) config('auth_security.login_otp_channel', 'email');
        $id = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        $ttl = max(1, min(30, (int) config('auth_security.email_otp_ttl_minutes', 10)));

        DB::transaction(function () use ($user, $remember, $channel, $id, $code, $ttl): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            DB::table('login_challenges')
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->delete();

            DB::table('login_challenges')->insert([
                'id' => $id,
                'user_id' => $user->getKey(),
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes($ttl),
                'attempts' => 0,
                'consumed_at' => null,
                'remember' => $remember,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return ['id' => $id, 'code' => $code, 'channel' => $channel];
    }
}
