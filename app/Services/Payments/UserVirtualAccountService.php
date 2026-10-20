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
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function provisionPaystackAccount(User $user, string $preferredBank = 'wema-bank'): UserVirtualAccount
    {
        if (!config('services.semizzy_virtual_accounts.enabled', false)) {
            throw new LogicException('Dedicated virtual-account provisioning is disabled until provider onboarding and deposit reconciliation are verified.');
        }
        if (!in_array($preferredBank, ['wema-bank', 'titan-paystack'], true)) {
            throw new \InvalidArgumentException('Choose a supported virtual-account bank.');
        }

        $existing = UserVirtualAccount::query()
            ->where('user_id', $user->getKey())
            ->where('provider', 'paystack')
            ->where('bank_code', $preferredBank)
            ->whereNull('disabled_at')
            ->first();
        if ($existing) {
            return $existing;
        }

        $name = trim((string) ($user->name ?? ''));
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $firstName = (string) ($parts[0] ?? '');
        $lastName = (string) ($parts[1] ?? '');
        if ($firstName === '') {
            $firstName = 'Semizzy';
        }
        if ($lastName === '') {
            $lastName = 'Customer';
        }

        $customer = $this->paystack->createOrFetchCustomer(
            (string) $user->email,
            $firstName,
            $lastName,
            (string) ($user->phone ?? ''),
        );
        $customerCode = (string) $customer['customer_code'];

        // Provider assignment can be asynchronous/ambiguous. The client fails closed
        // on transport errors; callers must reconcile rather than auto-retry blindly.
        $assigned = $this->paystack->assignDedicatedVirtualAccount($customerCode, $preferredBank);
        $account = $assigned['account'];
        $bank = $account['bank'] ?? [];

        try {
            return DB::transaction(function () use ($user, $customerCode, $assigned, $account, $bank, $preferredBank): UserVirtualAccount {
                $lockedExisting = UserVirtualAccount::query()
                    ->where('user_id', $user->getKey())
                    ->where('provider', 'paystack')
                    ->where('bank_code', $preferredBank)
                    ->whereNull('disabled_at')
                    ->lockForUpdate()
                    ->first();
                if ($lockedExisting) {
                    return $lockedExisting;
                }

                return UserVirtualAccount::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->getKey(),
                    'provider' => 'paystack',
                    'provider_customer_reference' => $customerCode,
                    'provider_account_reference' => is_string($assigned['id'] ?? null) || is_int($assigned['id'] ?? null)
                        ? (string) $assigned['id'] : null,
                    'bank_code' => $preferredBank,
                    'bank_name' => (string) ($bank['name'] ?? ''),
                    'account_name' => (string) ($account['account_name'] ?? ''),
                    'account_number' => (string) ($account['account_number'] ?? ''),
                    'currency' => 'NGN',
                    'status' => 'active',
                    'verified_at' => now(),
                    'metadata' => [
                        'provider' => 'paystack',
                        'assignment_status' => (string) ($assigned['assignment'] ?? 'active'),
                        'integration_mode' => app(PaystackClient::class)->mode(),
                    ],
                ]);
            }, attempts: 3);
        } catch (QueryException $exception) {
            // A uniqueness collision can occur if a concurrent request provisioned
            // the same provider account. Return only an account belonging to this user.
            $sameUser = UserVirtualAccount::query()
                ->where('user_id', $user->getKey())
                ->where('provider', 'paystack')
                ->where('bank_code', $preferredBank)
                ->whereNull('disabled_at')
                ->first();
            if ($sameUser) {
                return $sameUser;
            }

            throw new LogicException('Virtual-account details need provider reconciliation before retrying.', previous: $exception);
        }
    }
}
