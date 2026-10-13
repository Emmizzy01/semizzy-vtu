<?php

namespace App\Services\Vtu;

use App\Enums\VtuOrderStatus;
use App\Models\User;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Models\Wallet;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VtuOrderService
{
    public function __construct(
        private readonly WalletAccountService $walletAccounts,
        private readonly WalletLedgerService $ledger,
        private readonly VtuProviderClient $provider,
    ) {
    }

    /**
     * Create and debit an order once, then submit it outside the database
     * transaction. A repeat request returns the existing order and never
     * submits a second provider purchase.
     */
    public function purchase(
        User $user,
        string $productCode,
        string $recipient,
        string $idempotencyKey,
    ): VtuOrder {
        $this->provider->assertConfigured();

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('Idempotency key must contain 1 to 128 characters.');
        }

        $recipient = preg_replace('/[\s-]+/', '', $recipient) ?? '';
        if (! preg_match('/^\+?[0-9]{10,15}$/', $recipient)) {
            throw new InvalidArgumentException('Recipient must be a valid phone number.');
        }

        /** @var VtuProduct $product */
        $product = VtuProduct::query()
            ->where('code', $productCode)
            ->where('is_active', true)
            ->whereIn('service_type', ['airtime', 'data'])
            ->where('currency', 'NGN')
            ->firstOrFail();

        $requestHash = hash('sha256', json_encode([
            'user_id' => $user->getKey(),
            'product_code' => $product->code,
            'recipient' => $recipient,
            'amount_minor' => $product->price_minor,
            'currency' => $product->currency,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $existing = VtuOrder::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->returnExisting($existing, $requestHash);
        }

        $wallet = Wallet::query()
            ->where('user_id', $user->getKey())
            ->where('currency', 'NGN')
            ->first();

        if ($wallet === null) {
            $wallet = $this->walletAccounts->openForUser($user, 'NGN');
        }

        try {
            $order = DB::transaction(function () use ($user, $wallet, $product, $recipient, $idempotencyKey, $requestHash): VtuOrder {
                $duplicate = VtuOrder::query()
                    ->where('user_id', $user->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($duplicate !== null) {
                    return $this->returnExisting($duplicate, $requestHash);
                }

                $order = VtuOrder::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->getKey(),
                    'wallet_id' => $wallet->getKey(),
                    'vtu_product_id' => $product->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'service_type' => $product->service_type,
                    'network' => $product->network,
                    'product_code' => $product->code,
                    'recipient' => $recipient,
                    'amount_minor' => $product->price_minor,
                    'currency' => $product->currency,
                    'status' => VtuOrderStatus::Pending->value,
                    'provider' => (string) config('services.vtu.name', 'configured_vtu'),
                ]);

                $transaction = $this->ledger->post(
                    walletId: (int) $wallet->getKey(),
                    type: 'debit',
                    amountMinor: (int) $product->price_minor,
                    idempotencyKey: 'vtu-purchase:'.$order->public_id,
                    reference: $order->public_id,
                    description: 'VTU purchase: '.$product->name,
                    actorUserId: (int) $user->getKey(),
                    metadata: [
                        'vtu_order_id' => $order->public_id,
                        'product_code' => $product->code,
                        'recipient' => $recipient,
                    ],
                    correlationId: $order->public_id,
                );

                $order->forceFill([
                    'wallet_transaction_id' => $transaction->getKey(),
                    // Persist the dispatch marker before making the network call.
                    // If the process dies after this point, retries must reconcile.
                    'status' => VtuOrderStatus::Processing->value,
                    'dispatch_started_at' => now(),
                ])->save();

                return $order;
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = VtuOrder::query()
                ->where('user_id', $user->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->returnExisting($existing, $requestHash);
        }

        // A duplicate discovered inside the transaction is already in progress
        // or terminal. Do not call the provider a second time.
        if ($order->wasRecentlyCreated === false) {
            return $this->returnExisting($order, $requestHash);
        }

        try {
            $result = $this->provider->submit($order);
        } catch (ConnectionException|LogicException $exception) {
            report($exception);
            return $this->markAwaitingReconciliation($order, 'provider_result_unknown');
        }

        return $this->applyProviderResult($order, $result);
    }

    public function reconcile(VtuOrder $order): VtuOrder
    {
        if (! in_array($order->status, [
            VtuOrderStatus::Processing->value,
            VtuOrderStatus::AwaitingReconciliation->value,
        ], true) || $order->dispatch_started_at === null) {
            return $order;
        }

        try {
            $result = $this->provider->lookup($order);
        } catch (ConnectionException|LogicException $exception) {
            report($exception);
            return $order->fresh();
        }

        return $this->applyProviderResult($order, $result);
    }

    private function returnExisting(VtuOrder $order, string $requestHash): VtuOrder
    {
        if (! hash_equals($order->request_hash, $requestHash)) {
            throw new ConflictHttpException('This idempotency key was already used for a different purchase.');
        }

        return $order;
    }

    /** @param array<string, mixed> $result */
    private function applyProviderResult(VtuOrder $order, array $result): VtuOrder
    {
        $providerStatus = $result['status'] ?? 'unknown';

        if ($providerStatus === 'unknown') {
            return $this->markAwaitingReconciliation($order, 'provider_result_unknown', $result);
        }

        if ($providerStatus === 'pending') {
            $order->forceFill([
                'status' => VtuOrderStatus::Processing->value,
                'provider_reference' => $result['provider_reference'] ?? $order->provider_reference,
                'safe_provider_response' => $this->safeResponse($result),
            ])->save();

            return $order->fresh();
        }

        return DB::transaction(function () use ($order, $providerStatus, $result): VtuOrder {
            /** @var VtuOrder $locked */
            $locked = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [
                VtuOrderStatus::Successful->value,
                VtuOrderStatus::Failed->value,
                VtuOrderStatus::Cancelled->value,
            ], true)) {
                return $locked;
            }

            $next = $providerStatus === 'success'
                ? VtuOrderStatus::Successful
                : VtuOrderStatus::Failed;
            $current = VtuOrderStatus::from($locked->status);

            if (! $current->canTransitionTo($next)) {
                throw new LogicException('The VTU order status transition is not permitted.');
            }

            if ($providerStatus === 'failed') {
                // Only a definitive provider failure is refundable. An ambiguous
                // timeout must remain debited until provider state is reconciled.
                $this->ledger->post(
                    walletId: (int) $locked->wallet_id,
                    type: 'credit',
                    amountMinor: (int) $locked->amount_minor,
                    idempotencyKey: 'vtu-refund:'.$locked->public_id,
                    reference: $locked->public_id,
                    description: 'Refund for failed VTU purchase',
                    actorUserId: (int) $locked->user_id,
                    metadata: [
                        'vtu_order_id' => $locked->public_id,
                        'reason' => 'provider_definitive_failure',
                    ],
                    correlationId: $locked->public_id,
                );
            }

            $locked->forceFill([
                'status' => $next->value,
                'provider_reference' => $result['provider_reference'] ?? $locked->provider_reference,
                'failure_code' => $providerStatus === 'failed'
                    ? ($result['message_code'] ?? 'provider_failed')
                    : null,
                'safe_provider_response' => $this->safeResponse($result),
                'completed_at' => now(),
            ])->save();

            return $locked->fresh();
        }, attempts: 3);
    }

    /** @param array<string, mixed> $result */
    private function markAwaitingReconciliation(VtuOrder $order, string $failureCode, array $result = []): VtuOrder
    {
        return DB::transaction(function () use ($order, $failureCode, $result): VtuOrder {
            /** @var VtuOrder $locked */
            $locked = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [
                VtuOrderStatus::Successful->value,
                VtuOrderStatus::Failed->value,
                VtuOrderStatus::Cancelled->value,
            ], true)) {
                return $locked;
            }

            $current = VtuOrderStatus::from($locked->status);
            if ($current !== VtuOrderStatus::AwaitingReconciliation
                && $current->canTransitionTo(VtuOrderStatus::AwaitingReconciliation)) {
                $locked->status = VtuOrderStatus::AwaitingReconciliation->value;
            }

            $locked->failure_code = $failureCode;
            $locked->safe_provider_response = $this->safeResponse($result);
            $locked->save();

            return $locked->fresh();
        }, attempts: 3);
    }

    /** @param array<string, mixed> $result
     *  @return array<string, scalar|null>
     */
    private function safeResponse(array $result): array
    {
        // Never store request headers, tokens, or raw provider payloads.
        return [
            'status' => is_string($result['status'] ?? null) ? $result['status'] : null,
            'provider_reference' => is_string($result['provider_reference'] ?? null)
                ? substr($result['provider_reference'], 0, 150)
                : null,
            'message_code' => is_string($result['message_code'] ?? null)
                ? substr($result['message_code'], 0, 80)
                : null,
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($state, ['23000', '23505'], true)
            && in_array($driverCode, [19, 1062, 1555, 2067], true);
    }
}
