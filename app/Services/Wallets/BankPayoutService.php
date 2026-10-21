<?php

namespace App\Services\Wallets;

use App\Exceptions\Wallet\IdempotencyConflict;
use App\Models\BankBeneficiary;
use App\Models\BankDirectoryEntry;
use App\Models\BankPayout;
use App\Models\User;
use App\Models\Wallet;
use App\Services\IntegrationSettings;
use App\Services\Payments\PaystackClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class BankPayoutService
{
    public function __construct(
        private readonly PaystackClient $paystack,
        private readonly IntegrationSettings $settings,
        private readonly WalletAccountService $wallets,
        private readonly WalletLedgerService $ledger,
    ) {}

    public function banks(): array
    {
        try {
            $providerBanks = $this->paystack->listNigerianBanks();
            $normalized = [];

            foreach ($providerBanks as $bank) {
                $name = trim((string) ($bank['name'] ?? ''));
                $code = trim((string) ($bank['code'] ?? ''));

                if ($name === '' || strlen($name) > 120
                    || ! preg_match('/^[A-Za-z0-9_-]{2,20}$/', $code)) {
                    continue;
                }

                $normalized[$code] = ['name' => $name, 'code' => $code];
            }

            if ($normalized === []) {
                throw new LogicException('Bank provider returned no usable Nigerian banks.');
            }

            DB::transaction(function () use ($normalized): void {
                foreach ($normalized as $bank) {
                    BankDirectoryEntry::query()->updateOrCreate(
                        ['provider' => 'paystack', 'bank_code' => $bank['code']],
                        [
                            'bank_name' => $bank['name'],
                            'enabled' => true,
                            'provider_updated_at' => now(),
                        ],
                    );
                }
            }, attempts: 3);
        } catch (LogicException $exception) {
            // Cached banks are display-only during provider outages; account verification
            // is still performed live before any beneficiary can be saved.
            $cached = BankDirectoryEntry::query()
                ->where('provider', 'paystack')
                ->where('enabled', true)
                ->orderBy('bank_name')
                ->get(['bank_name', 'bank_code']);

            if ($cached->isEmpty()) {
                throw $exception;
            }
        }

        return BankDirectoryEntry::query()
            ->where('provider', 'paystack')
            ->where('enabled', true)
            ->orderBy('bank_name')
            ->get(['bank_name', 'bank_code'])
            ->map(static fn (BankDirectoryEntry $bank): array => [
                'name' => $bank->bank_name,
                'code' => $bank->bank_code,
            ])
            ->all();
    }

    public function beneficiaries(User $user)
    {
        return BankBeneficiary::query()->where('user_id', $user->id)->latest('id')->get();
    }

    public function addBeneficiary(User $user, string $bankCode, string $accountNumber): BankBeneficiary
    {
        $bank = collect($this->banks())->first(fn (array $item) => (string) $item['code'] === $bankCode);
        if (! is_array($bank)) {
            throw new InvalidArgumentException('Select a bank from the current bank directory.');
        }

        // Preserve the fast path for already-saved accounts. The claim table below
        // closes the race between this lookup and the provider recipient call.
        $matchingAccount = BankBeneficiary::query()
            ->where('bank_code', $bankCode)
            ->orderBy('id')
            ->get(['id', 'user_id', 'bank_code', 'account_number', 'account_name', 'provider_recipient_code'])
            ->first(fn (BankBeneficiary $saved): bool => hash_equals(
                (string) $saved->account_number,
                $accountNumber,
            ));

        if ($matchingAccount !== null) {
            if ((int) $matchingAccount->user_id !== (int) $user->id) {
                throw new LogicException('This bank account is already associated with another Semizzy account.');
            }

            return $matchingAccount;
        }

        // A keyed fingerprint avoids storing/indexing plaintext account numbers.
        // The unique DB constraint serializes competing requests before either
        // request can create a provider-side recipient.
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new LogicException('Bank beneficiary protection is unavailable because the application key is missing.');
        }
        $fingerprint = hash_hmac('sha256', $bankCode.'|'.trim($accountNumber), $appKey);

        $claimResult = DB::transaction(function () use ($fingerprint, $user): array {
            $inserted = DB::table('bank_beneficiary_claims')->insertOrIgnore([
                'account_fingerprint' => $fingerprint,
                'user_id' => $user->id,
                'beneficiary_id' => null,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $claim = DB::table('bank_beneficiary_claims')
                ->where('account_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($claim === null) {
                throw new LogicException('Could not reserve this bank account safely. Please try again later.');
            }

            return ['claim' => $claim, 'inserted' => $inserted === 1];
        }, attempts: 3);

        $claim = $claimResult['claim'];
        if ((int) $claim->user_id !== (int) $user->id) {
            throw new LogicException('This bank account is already associated with another Semizzy account.');
        }

        if ($claim->beneficiary_id !== null) {
            $claimedBeneficiary = BankBeneficiary::query()->find($claim->beneficiary_id);
            if ($claimedBeneficiary !== null && (int) $claimedBeneficiary->user_id === (int) $user->id) {
                return $claimedBeneficiary;
            }

            throw new LogicException('This bank account is reserved for verification recovery. Contact support; do not submit it again.');
        }

        // Only the request that inserted the unique claim may contact the provider.
        // Existing pending claims are never retried because the prior provider call
        // may have succeeded even if its response was lost.
        if (! $claimResult['inserted']) {
            throw new LogicException('This bank account is already being verified. If you just submitted it, wait or contact support before retrying.');
        }

        $resolved = $this->paystack->resolveNigerianAccount($accountNumber, $bankCode);
        $recipient = $this->paystack->createTransferRecipient($resolved['account_name'], $accountNumber, $bankCode);
        $recipientCode = $recipient['recipient_code'];

        $existing = BankBeneficiary::query()->where('provider_recipient_code', $recipientCode)->first();
        if ($existing !== null) {
            if ((int) $existing->user_id !== (int) $user->id) {
                throw new LogicException('This provider recipient is already associated with another Semizzy account.');
            }

            DB::table('bank_beneficiary_claims')
                ->where('account_fingerprint', $fingerprint)
                ->update(['beneficiary_id' => $existing->id, 'status' => 'complete', 'updated_at' => now()]);

            return $existing;
        }

        return DB::transaction(function () use ($user, $bank, $bankCode, $accountNumber, $resolved, $recipientCode, $fingerprint): BankBeneficiary {
            $beneficiary = BankBeneficiary::query()->create([
                'user_id' => $user->id,
                'bank_code' => $bankCode,
                'bank_name' => (string) $bank['name'],
                'account_number' => $accountNumber,
                'account_number_last4' => substr($accountNumber, -4),
                'account_name' => $resolved['account_name'],
                'provider_recipient_code' => $recipientCode,
                'verification_status' => 'verified',
                'verified_at' => now(),
            ]);

            DB::table('bank_beneficiary_claims')
                ->where('account_fingerprint', $fingerprint)
                ->where('user_id', $user->id)
                ->update([
                    'beneficiary_id' => $beneficiary->id,
                    'status' => 'complete',
                    'updated_at' => now(),
                ]);

            return $beneficiary;
        }, attempts: 3);
    }

    public function removeBeneficiary(User $user, int $beneficiaryId): void
    {
        DB::transaction(function () use ($user, $beneficiaryId): void {
            $beneficiary = BankBeneficiary::query()
                ->where('user_id', $user->id)
                ->whereKey($beneficiaryId)
                ->lockForUpdate()
                ->firstOrFail();

            $hasPayoutHistory = BankPayout::query()
                ->where('bank_beneficiary_id', $beneficiary->id)
                ->exists();

            if ($hasPayoutHistory) {
                throw new LogicException('This saved bank account has transfer history and cannot be removed. Keep it for your records.');
            }

            // Release the unique account claim only when removal is allowed.
            // Accounts with payout history are rejected above and keep their claim.
            DB::table('bank_beneficiary_claims')
                ->where('beneficiary_id', $beneficiary->id)
                ->delete();

            $beneficiary->delete();
        }, attempts: 3);
    }

    public function requestPayout(User $user, int $beneficiaryId, int $amountMinor, string $idempotencyKey): BankPayout
    {
        if ($amountMinor < 100 || $amountMinor > 500000000) {
            throw new InvalidArgumentException('Bank transfer amount must be between ₦1 and ₦5,000,000.');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('A valid idempotency key is required.');
        }
        if (! filter_var($this->settings->get('paystack.transfers_enabled', '0'), FILTER_VALIDATE_BOOLEAN)) {
            throw new LogicException('Bank payouts are disabled by the administrator until the payout provider is configured and verified.');
        }

        $beneficiary = BankBeneficiary::query()
            ->where('user_id', $user->id)
            ->whereKey($beneficiaryId)
            ->where('verification_status', 'verified')
            ->whereNotNull('verified_at')
            ->firstOrFail();

        $requestHash = hash('sha256', json_encode([
            'user_id' => $user->id,
            'beneficiary_id' => $beneficiary->id,
            'amount_minor' => $amountMinor,
            'currency' => 'NGN',
        ], JSON_THROW_ON_ERROR));

        $isNewRequest = false;
        $payout = DB::transaction(function () use ($user, $beneficiary, $amountMinor, $idempotencyKey, $requestHash, &$isNewRequest): BankPayout {
            // Laravel may retry this closure after a deadlock. Reset the
            // side-effect flag for every attempt so an aborted attempt can
            // never cause a reused payout to be submitted to the provider.
            $isNewRequest = false;

            $existing = BankPayout::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new IdempotencyConflict('This payout key was already used for a different request.');
                }
                return $existing;
            }

            $wallet = $this->wallets->openForUser($user, 'NGN');
            $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            // The wallet row is the per-customer serialization point. A second
            // independent request may have passed the first idempotency lookup
            // while the first request was still in flight; re-check only after
            // acquiring this lock so it returns the committed payout instead
            // of attempting a second debit or relying on a unique-key exception.
            $existing = BankPayout::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new IdempotencyConflict('This payout key was already used for a different request.');
                }

                return $existing;
            }

            $publicId = (string) Str::uuid();
            $reference = 'semizzy_'.str_replace('-', '', $publicId);

            $transaction = $this->ledger->post(
                walletId: $wallet->id,
                type: 'debit',
                amountMinor: $amountMinor,
                idempotencyKey: 'bank-payout:'.$publicId.':debit',
                reference: $publicId,
                description: 'Bank payout to '.$beneficiary->bank_name.' account ending '.$beneficiary->account_number_last4,
                actorUserId: $user->id,
                metadata: ['payout_id' => $publicId, 'beneficiary_id' => $beneficiary->id],
            );

            $isNewRequest = true;
            return BankPayout::query()->create([
                'public_id' => $publicId,
                'user_id' => $user->id,
                'bank_beneficiary_id' => $beneficiary->id,
                'currency' => 'NGN',
                'amount_minor' => $amountMinor,
                'fee_minor' => 0,
                'status' => 'queued',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'provider_reference' => $reference,
                'wallet_transaction_id' => $transaction->id,
                'metadata' => ['provider' => 'paystack', 'mode' => $this->paystack->mode()],
            ]);
        }, attempts: 3);

        // An existing request is never submitted to the provider again.
        if (! $isNewRequest) {
            return $payout;
        }

        try {
            $response = $this->paystack->initiateBankTransfer(
                (int) $payout->amount_minor,
                (string) $beneficiary->provider_recipient_code,
                (string) $payout->provider_reference,
                'Semizzy user bank payout '.$payout->public_id,
            );
            $providerStatus = strtolower((string) ($response['status'] ?? 'pending'));
            if (! is_string($response['transfer_code'] ?? null) || $response['transfer_code'] === '') {
                $payout->forceFill(['status' => 'reconciliation_required', 'failure_reason' => 'Provider accepted no usable transfer identifier; manual reconciliation is required.'])->save();
                return $payout->fresh(['beneficiary']);
            }
            $payout->forceFill([
                'status' => $providerStatus === 'failed' ? 'reconciliation_required' : 'provider_pending',
                'provider_transfer_code' => is_string($response['transfer_code'] ?? null) ? $response['transfer_code'] : null,
                'metadata' => array_merge($payout->metadata ?? [], ['provider_status' => $providerStatus]),
            ])->save();
        } catch (LogicException $exception) {
            report($exception);
            $payout->forceFill([
                'status' => 'reconciliation_required',
                'failure_reason' => 'Provider outcome is not confirmed. Do not retry automatically.',
            ])->save();
        }

        return $payout->fresh(['beneficiary']);
    }

    public function history(User $user)
    {
        return BankPayout::query()->with('beneficiary')->where('user_id', $user->id)->latest('id')->paginate(20);
    }
}
