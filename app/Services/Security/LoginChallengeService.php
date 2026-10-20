<?php

namespace App\Services\Security;

use App\Mail\LoginOtpMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class LoginChallengeService
{
    public function start(Request $request, User $user, bool $remember): void
    {
        $channel = (string) config('auth_security.login_otp_channel', 'email');
        $id = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        $ttl = max(1, min(30, (int) config('auth_security.email_otp_ttl_minutes', 10)));

        DB::table('login_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->delete();
        DB::table('login_challenges')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($ttl),
            'attempts' => 0,
            'consumed_at' => null,
            'remember' => $remember,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            Mail::to($user->email)->send(new LoginOtpMail($code));
        } catch (\Throwable $exception) {
            DB::table('login_challenges')->where('id', $id)->delete();
            throw $exception;
        }

        // Rotate the pre-auth session before binding it to the challenge.
        $request->session()->regenerate();
        $request->session()->put('auth.login_challenge_id', $id);
    }
}
