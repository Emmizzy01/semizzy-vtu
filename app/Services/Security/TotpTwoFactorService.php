<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TotpTwoFactorService
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function newSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(User $user, string $secret): string
    {
        $issuer = rawurlencode((string) config('app.name', 'Semizzy One'));
        $account = rawurlencode((string) $user->email);
        return "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    /** @return array<int, string> Plain recovery codes are shown once only. */
    public function enable(User $user, string $secret): array
    {
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_enabled_at' => now(),
        ])->save();

        DB::table('user_two_factor_recovery_codes')->where('user_id', $user->id)->delete();
        $codes = [];
        foreach (range(1, 10) as $_) {
            $code = strtoupper(Str::random(5).'-'.Str::random(5));
            $codes[] = $code;
            DB::table('user_two_factor_recovery_codes')->insert([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'used_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $codes;
    }

    public function verifyUserCode(User $user, string $code): bool
    {
        if (!$user->two_factor_enabled_at || !is_string($user->two_factor_secret) || $user->two_factor_secret === '') {
            return false;
        }

        try {
            $secret = Crypt::decryptString($user->two_factor_secret);
        } catch (\Throwable) {
            return false;
        }

        if (preg_match('/^\d{6}$/', $code) && $this->verifyTotp($secret, $code)) {
            return true;
        }

        if (!preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', strtoupper($code))) {
            return false;
        }

        return DB::transaction(function () use ($user, $code): bool {
            $records = DB::table('user_two_factor_recovery_codes')
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                if (Hash::check(strtoupper($code), $record->code_hash)) {
                    DB::table('user_two_factor_recovery_codes')->where('id', $record->id)
                        ->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
                    return DB::table('user_two_factor_recovery_codes')->where('id', $record->id)->whereNotNull('used_at')->exists();
                }
            }

            return false;
        });
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_enabled_at' => null])->save();
        DB::table('user_two_factor_recovery_codes')->where('user_id', $user->id)->delete();
    }

    private function verifyTotp(string $secret, string $code): bool
    {
        $key = $this->base32Decode($secret);
        if ($key === '') return false;
        $counter = intdiv(now()->timestamp, 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            $binaryCounter = pack('N2', 0, $counter + $offset);
            $hash = hash_hmac('sha1', $binaryCounter, $key, true);
            $position = ord($hash[19]) & 0x0f;
            $number = ((ord($hash[$position]) & 0x7f) << 24)
                | ((ord($hash[$position + 1]) & 0xff) << 16)
                | ((ord($hash[$position + 2]) & 0xff) << 8)
                | (ord($hash[$position + 3]) & 0xff);
            if (hash_equals(str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT), $code)) return true;
        }
        return false;
    }

    private function base32Encode(string $input): string
    {
        $bits = '';
        foreach (str_split($input) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::BASE32[bindec($chunk)];
        }
        return $out;
    }

    private function base32Decode(string $input): string
    {
        $input = strtoupper(rtrim($input, '='));
        if ($input === '' || preg_match('/^[A-Z2-7]+$/', $input) !== 1) return '';
        $bits = '';
        foreach (str_split($input) as $char) $bits .= str_pad(decbin(strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        }
        return $out;
    }
}
