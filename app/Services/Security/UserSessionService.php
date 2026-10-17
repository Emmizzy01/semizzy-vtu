<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserSessionService
{
    public function currentHash(Request $request): string
    {
        // Track a random per-session fingerprint stored in the server-side session
        // payload. Unlike hashing Laravel's session ID directly, this remains
        // stable when Laravel rotates the session ID after login.
        $fingerprint = $request->session()->get('semizzy_session_fingerprint');
        if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            $fingerprint = bin2hex(random_bytes(32));
            $request->session()->put('semizzy_session_fingerprint', $fingerprint);
        }

        return hash('sha256', $fingerprint);
    }

    public function registerCurrent(Request $request, User $user): bool
    {
        $hash = $this->currentHash($request);
        $existing = DB::table('user_sessions')->where('session_hash', $hash)->first();

        if ($existing) {
            if ((int) $existing->user_id !== (int) $user->id || $existing->revoked_at !== null) {
                return false;
            }
            DB::table('user_sessions')->where('session_hash', $hash)->update([
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'last_activity' => now(), 'updated_at' => now(),
            ]);
            return true;
        }

        DB::table('user_sessions')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'session_hash' => $hash,
            'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'last_activity' => now(), 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $record = DB::table('user_sessions')->where('session_hash', $hash)->first();
        return $record !== null && (int) $record->user_id === (int) $user->id && $record->revoked_at === null;
    }

    public function rotateCurrent(Request $request, User $user, string $previousHash): void
    {
        // Password changes keep the current login but must issue a fresh session
        // fingerprint before the previous tracked session is revoked.
        $request->session()->put('semizzy_session_fingerprint', bin2hex(random_bytes(32)));
        $this->registerCurrent($request, $user);
        DB::table('user_sessions')->where('user_id', $user->id)->where('session_hash', $previousHash)
            ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function revokeOthers(User $user, string $currentHash): int
    {
        return DB::table('user_sessions')->where('user_id', $user->id)->where('session_hash', '!=', $currentHash)
            ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function revokeAll(User|int $user): int
    {
        $userId = $user instanceof User ? $user->id : $user;
        return DB::table('user_sessions')->where('user_id', $userId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function revoke(User $user, string $id): bool
    {
        return DB::table('user_sessions')->where('id', $id)->where('user_id', $user->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]) === 1;
    }
}
