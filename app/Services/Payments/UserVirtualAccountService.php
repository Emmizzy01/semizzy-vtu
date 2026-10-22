<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Models\UserVirtualAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class UserVirtualAccountService
{
    public function __construct(private readonly PaystackClient $paystack)
    {
    }

    /** @return array<int, UserVirtualAccount> */
    public function accountsFor(User $user): array
    {
        return UserVirtualAccount::query()
            ->where('user_id', $user->getKey())
            ->whereNull('disabled_at')
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function provisionPaystackAccount(User $user, string $preferredBank = 'wema-bank', bool $consent = false): UserVirtualAccount
    {
        if (!config('services.semizzy_virtual_accounts.enabled', false)) {
            throw new LogicException('Dedicated virtual-account provisioning is disabled until provider onboarding and deposit reconciliation are verified.');
        }
        if (!$consent) {
            throw new \InvalidArgumentException('Consent is required before sending customer details to the payment provider.');
        }
        if (!in_array($preferredBank, ['wema-bank', 'titan-paystack'], true)) {
            throw new \InvalidArgumentException('Choose a supported virtual-account bank.');
        }

        $gatewayMode = $this->paystack->mode();
        if ($gatewayMode === 'test') {
            $preferredBank = 'test-bank';
        }

        // Reserve one account slot per user/provider before any network call.
        // A pending row survives timeouts so a later request can inspect provider state
        // instead of blindly assigning another account.
        $claimToken = (string) Str::uuid();

        $account = DB::transaction(function () use ($user, $preferredBank, $gatewayMode, $claimToken): UserVirtualAccount {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = UserVirtualAccount::query()
                ->where('user_id', $user->getKey())
                ->where('provider', 'paystack')
                ->whereNull('disabled_at')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->status === 'active') {
                    return $existing;
                }
                if ($existing->status !== 'provisioning') {
                    throw new LogicException('Existing virtual-account state requires operator reconciliation.');
                }

                $metadata = $existing->metadata ?? [];
                $claimedAt = $metadata['provisioning_claimed_at'] ?? null;
                if (is_string($claimedAt) && $claimedAt !== '') {
                    try {
                        $claimTime = \\Illuminate\\Support\\Carbon::parse($claimedAt);
                    } catch (\\Throwable $exception) {
                        throw new LogicException('Virtual-account provisioning claim is invalid; operator reconciliation is required.', previous: $exception);
                    }

                    // Do not allow two requests to issue provider assignment calls at
                    // once. A stale claim can be reclaimed only after the recovery
                    // window, and the final write verifies the claim token so a late
                    // worker cannot overwrite the newer worker's result.
                    if ($claimTime->greaterThan(now()->subMinutes(30))) {
                        throw new LogicException('Virtual-account provisioning is already in progress. Wait for reconciliation before retrying.');
                    }
                }

                $metadata['provisioning_claimed_at'] = now()->toIso8601String();
                $metadata['provisioning_claim_token'] = $claimToken;
                $existing->forceFill(['metadata' => $metadata])->save();

                return $existing;
            }

            return UserVirtualAccount::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'provider' => 'paystack',
                'bank_code' => $preferredBank,
                'currency' => 'NGN',
                'status' => 'provisioning',
                'consented_at' => now(),
                'metadata' => [
                    'integration_mode' => $gatewayMode,
                    'provisioning_claimed_at' => now()->toIso8601String(),
                    'provisioning_claim_token' => $claimToken,
                ],
            ]);
        }, attempts: 3);

        if ($account->status === 'active') {
            return $account;
        }

        try {
            $customerCode = (string) $account->provider_customer_reference;
            if ($customerCode === '') {
                $name = trim((string) ($user->name ?? ''));
                $parts = preg_split('/\s+/', $name, 2) ?: [];
                $firstName = (string) ($parts[0] ?? 'Semizzy');
                $lastName = (string) ($parts[1] ?? 'Customer');
                if ($firstName === '') $firstName = 'Semizzy';
                if ($lastName === '') $lastName = 'Customer';

                $customer = $this->paystack->createOrFetchCustomer(
                    (string) $user->email,
                    $firstName,
                    $lastName,
                    (string) ($user->mobile_number ?: $user->whatsapp_number ?: ''),
                );
                $customerCode = (string) $customer['customer_code'];
                $account->forceFill(['provider_customer_reference' => $customerCode])->save();
            }

            // Recover first: if a prior assignment succeeded but the response was lost,
            // fetch the provider customer and reuse its assigned account.
            $customerState = $this->paystack->fetchCustomer($customerCode);
            $assigned = $customerState['dedicated_account'] ?? null;
            if (!is_array($assigned) || !is_string($assigned['account_number'] ?? null)
                || !preg_match('/^\d{10}$/', $assigned['account_number'])) {
                $assigned = $this->paystack->assignDedicatedVirtualAccount($customerCode, $preferredBank);
            }

            $bank = is_array($assigned['bank'] ?? null) ? $assigned['bank'] : [];
            if (($assigned['assigned'] ?? false) !== true || ($assigned['active'] ?? false) !== true
                || strtoupper((string) ($assigned['currency'] ?? '')) !== 'NGN'
                || !is_string($assigned['account_number'] ?? null)
                || !preg_match('/^\d{10}$/', $assigned['account_number'])
                || !is_string($assigned['account_name'] ?? null) || trim($assigned['account_name']) === ''
                || !is_string($bank['name'] ?? null) || trim($bank['name']) === '') {
                throw new LogicException('Provider account assignment is incomplete; keep the reservation pending and reconcile before retrying.');
            }

            return DB::transaction(function () use ($account, $user, $customerCode, $assigned, $bank, $preferredBank, $gatewayMode, $claimToken): UserVirtualAccount {
                $locked = UserVirtualAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->status === 'active') {
                    return $locked;
                }
                if (($locked->metadata['provisioning_claim_token'] ?? null) !== $claimToken) {
                    throw new LogicException('Virtual-account provisioning claim changed while the provider request was in flight; reconcile provider state before retrying.');
                }

                $locked->forceFill([
                    'provider_customer_reference' => $customerCode,
                    'provider_account_reference' => isset($assigned['id']) ? (string) $assigned['id'] : null,
                    'bank_code' => (string) ($bank['slug'] ?? $preferredBank),
                    'bank_name' => (string) $bank['name'],
                    'account_name' => (string) $assigned['account_name'],
                    'account_number' => (string) $assigned['account_number'],
                    'currency' => (string) ($assigned['currency'] ?? 'NGN'),
                    'status' => 'active',
                    'verified_at' => now(),
                    'metadata' => [
                        'provider' => 'paystack',
                        'assignment_status' => (bool) ($assigned['assigned'] ?? true) ? 'assigned' : 'pending',
                        'integration_mode' => $gatewayMode,
                    ],
                ])->save();

                return $locked->fresh();
            }, attempts: 3);
        } catch (QueryException $exception) {
            throw new LogicException('Virtual-account assignment may have succeeded; reconcile provider state before any retry.', previous: $exception);
        }
    }
}
