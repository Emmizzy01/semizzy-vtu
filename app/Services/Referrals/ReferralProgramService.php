<?php

namespace App\Services\Referrals;

use App\Models\ReferralAttribution;
use App\Models\ReferralProfile;
use App\Models\User;
use App\Services\IntegrationSettings;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class ReferralProgramService
{
    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly WalletAccountService $wallets,
        private readonly WalletLedgerService $ledger,
    ) {}

    public function ensureProfile(User $user): ReferralProfile
    {
        $existing = ReferralProfile::query()->where('user_id', $user->id)->first();
        if ($existing) return $existing;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = strtoupper(Str::random(10));
            try {
                return ReferralProfile::query()->create(['user_id' => $user->id, 'code' => $code]);
            } catch (\Illuminate\Database\QueryException $exception) {
                $existing = ReferralProfile::query()->where('user_id', $user->id)->first();
                if ($existing) return $existing;
                if ($attempt === 4) throw $exception;
            }
        }

        throw new LogicException('Could not allocate a referral code.');
    }

    public function attributeNewUser(User $newUser, ?string $code): void
    {
        if (! $code) return;
        if (! filter_var($this->settings->get('referrals.enabled', false), FILTER_VALIDATE_BOOLEAN)) return;

        $profile = ReferralProfile::query()->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first();
        if (! $profile || (int) $profile->user_id === (int) $newUser->id) return;

        // A referral is only attributed once, at registration; later code changes cannot overwrite it.
        ReferralAttribution::query()->firstOrCreate(
            ['referred_user_id' => $newUser->id],
            [
                'referrer_user_id' => $profile->user_id,
                'referral_code' => $profile->code,
                'status' => 'pending',
                'reward_minor' => 0,
            ],
        );
    }

    public function dashboard(User $user): array
    {
        $profile = $this->ensureProfile($user);
        $items = ReferralAttribution::query()->where('referrer_user_id', $user->id);
        return [
            'enabled' => filter_var($this->settings->get('referrals.enabled', false), FILTER_VALIDATE_BOOLEAN),
            'code' => $profile->code,
            'link' => url('/register?ref='.$profile->code),
            'rewardNaira' => ((int) $this->settings->get('referrals.reward_minor', 0)) / 100,
            'qualifyingEvent' => (string) $this->settings->get('referrals.qualifying_event', 'admin_verified'),
            'stats' => [
                'total' => (clone $items)->count(),
                'pending' => (clone $items)->where('status', 'pending')->count(),
                'qualified' => (clone $items)->where('status', 'qualified')->count(),
                'rewarded' => (clone $items)->where('status', 'rewarded')->count(),
            ],
            'referrals' => $items->with('referredUser:id,name,created_at')->latest('id')->limit(100)->get()->map(fn (ReferralAttribution $item) => [
                'id' => $item->id,
                'name' => $item->referredUser?->name ?? 'Semizzy user',
                'joined_at' => $item->created_at?->toISOString(),
                'status' => $item->status,
                'reward_minor' => $item->reward_minor,
            ])->values(),
        ];
    }

    public function adminData(): array
    {
        return [
            'enabled' => filter_var($this->settings->get('referrals.enabled', false), FILTER_VALIDATE_BOOLEAN),
            'reward_minor' => (int) $this->settings->get('referrals.reward_minor', 0),
            'qualifying_event' => (string) $this->settings->get('referrals.qualifying_event', 'admin_verified'),
            'stats' => [
                'pending' => ReferralAttribution::query()->where('status', 'pending')->count(),
                'qualified' => ReferralAttribution::query()->where('status', 'qualified')->count(),
                'rewarded' => ReferralAttribution::query()->where('status', 'rewarded')->count(),
                'rejected' => ReferralAttribution::query()->where('status', 'rejected')->count(),
            ],
            'referrals' => ReferralAttribution::query()->with(['referrer:id,name,email', 'referredUser:id,name,email'])->latest('id')->paginate(30)->through(fn (ReferralAttribution $item) => [
                'id' => $item->id, 'status' => $item->status,
                'referrer_name' => $item->referrer?->name, 'referrer_email' => $item->referrer?->email,
                'referred_name' => $item->referredUser?->name, 'referred_email' => $item->referredUser?->email,
                'reward_minor' => $item->reward_minor, 'qualifying_reference' => $item->qualifying_reference,
                'review_note' => $item->review_note, 'created_at' => $item->created_at?->toISOString(),
            ]),
        ];
    }

    public function updateSettings(bool $enabled, int $rewardMinor, string $qualifyingEvent, User $actor, string $reason): void
    {
        if ($rewardMinor < 0 || $rewardMinor > 100000000) throw new InvalidArgumentException('Referral reward must be between ₦0 and ₦1,000,000.');
        if ($enabled && $rewardMinor < 1) throw new InvalidArgumentException('Set a reward greater than ₦0 before enabling referrals.');
        if (! in_array($qualifyingEvent, ['admin_verified'], true)) {
            throw new InvalidArgumentException('Choose a supported referral qualifying event.');
        }

        DB::transaction(function () use ($enabled, $rewardMinor, $qualifyingEvent, $actor, $reason): void {
            foreach ([
                'referrals.enabled' => $enabled ? '1' : '0',
                'referrals.reward_minor' => (string) $rewardMinor,
                'referrals.qualifying_event' => $qualifyingEvent,
            ] as $key => $value) {
                $old = (string) $this->settings->get($key, match ($key) {
                    'referrals.enabled' => '0',
                    'referrals.reward_minor' => '0',
                    default => 'admin_verified',
                });
                $this->settings->set($key, $value, (int) $actor->id);
                \App\Models\PlatformSettingAudit::query()->create([
                    'actor_user_id' => $actor->id, 'setting_key' => $key,
                    'old_value_hash' => hash('sha256', $old), 'new_value_hash' => hash('sha256', $value),
                    'reason' => $reason, 'created_at' => now(),
                ]);
            }
        }, attempts: 3);
    }

    public function review(int $id, string $decision, ?string $qualifyingReference, ?string $note, User $actor): ReferralAttribution
    {
        return DB::transaction(function () use ($id, $decision, $qualifyingReference, $note, $actor): ReferralAttribution {
            $item = ReferralAttribution::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (in_array($item->status, ['rewarded', 'rejected'], true)) {
                throw new LogicException('This referral has already been finalized.');
            }

            if ($decision === 'reject') {
                $item->forceFill([
                    'status' => 'rejected', 'reviewed_by_user_id' => $actor->id,
                    'review_note' => $note, 'qualifying_reference' => $qualifyingReference,
                ])->save();
                return $item;
            }

            if ($decision !== 'qualify' || trim((string) $qualifyingReference) === '') {
                throw new InvalidArgumentException('A qualifying reference is required to approve a referral.');
            }

            $amount = (int) $this->settings->get('referrals.reward_minor', 0);
            if ($amount < 1) {
                throw new LogicException('Enable referrals and configure a reward greater than zero before approving rewards.');
            }

            $wallet = $this->wallets->openForUser($item->referrer, 'NGN');
            $this->ledger->post(
                walletId: (int) $wallet->id,
                type: 'credit',
                amountMinor: $amount,
                idempotencyKey: 'referral:'.$item->id.':reward',
                reference: 'referral-'.$item->id,
                description: 'Referral reward for a verified qualifying event',
                actorUserId: (int) $actor->id,
                metadata: [
                    'referral_id' => $item->id,
                    'referred_user_id' => $item->referred_user_id,
                    'qualifying_reference' => trim($qualifyingReference),
                ],
            );

            $item->forceFill([
                'status' => 'rewarded', 'reward_minor' => $amount,
                'qualifying_reference' => trim($qualifyingReference),
                'reviewed_by_user_id' => $actor->id, 'review_note' => $note,
                'qualified_at' => now(), 'rewarded_at' => now(),
            ])->save();

            return $item;
        }, attempts: 3);
    }
}
