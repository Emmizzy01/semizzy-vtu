<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginChallengeVerificationService
{
    /**
     * Atomically consume a login challenge or count a failed code attempt.
     *
     * @return array{status: string, user_id?: int, remember?: bool}
     */
    public function verify(string $challengeId, string $code): array
    {
        return DB::transaction(function () use ($challengeId, $code): array {
            $challenge = DB::table('login_challenges')
                ->where('id', $challengeId)
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->consumed_at !== null || now()->greaterThanOrEqualTo($challenge->expires_at)) {
                return ['status' => 'expired'];
            }

            $maxAttempts = max(1, min(10, (int) config('auth_security.email_otp_max_attempts', 5)));
            if ((int) $challenge->attempts >= $maxAttempts) {
                return ['status' => 'locked'];
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                DB::table('login_challenges')->where('id', $challengeId)->increment('attempts');

                return ['status' => 'invalid'];
            }

            DB::table('login_challenges')->where('id', $challengeId)->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'status' => 'ok',
                'user_id' => (int) $challenge->user_id,
                'remember' => (bool) $challenge->remember,
            ];
        });
    }
}
