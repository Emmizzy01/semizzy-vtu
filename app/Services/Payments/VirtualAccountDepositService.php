<?php

namespace App\Services\Payments;

use App\Models\UserVirtualAccount;
use App\Models\UserVirtualAccountDeposit;
use App\Models\Wallet;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VirtualAccountDepositService
{
    public function __construct(private readonly WalletLedgerService $ledger)
    {
    }

    /**
     * Settle only a server-verified Paystack dedicated-NUBAN transaction.
     *
     * @param array<string, mixed> $verified
     * @param array<string, mixed> $webhook
     * @return array{outcome:string, duplicate:bool}
     */
    public function processVerifiedPaystackDeposit(array $verified, array $webhook, string $gatewayMode): array
    {
        if ($gatewayMode === 'test') {
            return ['outcome' => 'test_deposit_not_credited', 'duplicate' => false];
        }
        if ($gatewayMode !== 'live' || !config('services.semizzy_payments.live_funding_enabled', false)) {
            throw new LogicException('Virtual-account settlement is disabled until the live-funding release gate is approved.');
        }

        $transactionId = is_numeric($verified['id'] ?? null) ? (string) $verified['id'] : '';
        $reference = is_string($verified['reference'] ?? null) ? $verified['reference'] : '';
        $amount = $verified['amount'] ?? null;
        $currency = is_string($verified['currency'] ?? null) ? strtoupper($verified['currency']) : '';
        $status = $verified['status'] ?? null;
        $authorization = is_array($verified['authorization'] ?? null) ? $verified['authorization'] : [];
        $customer = is_array($verified['customer'] ?? null) ? $verified['customer'] : [];
        $receiverAccount = is_string($authorization['receiver_bank_account_number'] ?? null)
            ? $authorization['receiver_bank_account_number'] : '';
        $customerCode = is_string($customer['customer_code'] ?? null) ? $customer['customer_code'] : '';

        if ($status !== 'success' || $transactionId === '' || $reference === ''
            || !is_int($amount) || $amount < 1 || $currency !== 'NGN'
            || ($authorization['channel'] ?? null) !== 'dedicated_nuban'
            || !preg_match('/^\d{10}$/', $receiverAccount) || $customerCode === '') {
            throw new ConflictHttpException('Verified payment is not a complete dedicated virtual-account deposit.');
        }

        $payloadHash = hash('sha256', json_encode($webhook, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            return DB::transaction(function () use ($transactionId, $reference, $amount, $currency, $receiverAccount, $customerCode, $payloadHash): array {
                $account = UserVirtualAccount::query()
                    ->where('provider', 'paystack')
                    ->where('account_number', $receiverAccount)
                    ->where('provider_customer_reference', $customerCode)
                    ->where('status', 'active')
                    ->whereNull('disabled_at')
                    ->lockForUpdate()
                    ->first();

                if ($account === null) {
                    throw new ConflictHttpException('Verified deposit does not match an active account and customer assignment.');
                }

                $existing = UserVirtualAccountDeposit::query()
                    ->where('provider', 'paystack')
                    ->where('provider_transaction_id', $transactionId)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if (!hash_equals($existing->payload_hash, $payloadHash)
                        || $existing->user_virtual_account_id !== $account->id
                        || $existing->amount_minor !== $amount
                        || $existing->provider_reference !== $reference) {
                        throw new ConflictHttpException('Provider transaction ID was reused with conflicting virtual-account details.');
                    }

                    return ['outcome' => $existing->status, 'duplicate' => true];
                }

                $wallet = Wallet::query()->where('user_id', $account->user_id)->where('currency', $currency)->lockForUpdate()->first();
                if ($wallet === null) {
                    throw new LogicException('Wallet for the virtual-account owner is missing; reconcile before settlement.');
                }

                $deposit = UserVirtualAccountDeposit::query()->create([
                    'user_virtual_account_id' => $account->id,
                    'user_id' => $account->user_id,
                    'provider' => 'paystack',
                    'provider_transaction_id' => $transactionId,
                    'provider_reference' => $reference,
                    'amount_minor' => $amount,
                    'currency' => $currency,
                    'status' => 'settling',
                    'payload_hash' => $payloadHash,
                ]);

                $transaction = $this->ledger->post(
                    (int) $wallet->id,
                    'credit',
                    $amount,
                    'paystack-dva:'.$transactionId,
                    $reference,
                    'Verified dedicated virtual-account deposit',
                    null,
                    [
                        'source' => 'paystack_dedicated_virtual_account',
                        'virtual_account_public_id' => $account->public_id,
                        'provider_transaction_id' => $transactionId,
                    ],
                );

                $deposit->forceFill([
                    'status' => 'settled',
                    'wallet_transaction_id' => $transaction->id,
                    'settled_at' => now(),
                ])->save();

                return ['outcome' => 'settled', 'duplicate' => false];
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = UserVirtualAccountDeposit::query()
                ->where('provider', 'paystack')
                ->where('provider_transaction_id', $transactionId)
                ->first();
            if ($existing && hash_equals($existing->payload_hash, $payloadHash)
                && $existing->amount_minor === $amount && $existing->provider_reference === $reference) {
                return ['outcome' => $existing->status, 'duplicate' => true];
            }

            throw new LogicException('Virtual-account deposit settlement needs reconciliation before retrying.', previous: $exception);
        }
    }
}
