<?php

namespace App\Services\Wallets;

use App\Exceptions\Wallet\IdempotencyConflict;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PeerWalletTransferService
{
    public function __construct(
        private readonly WalletAccountService $wallets,
        private readonly WalletLedgerService $ledger,
    ) {}

    public function transfer(User $sender, string $recipientIdentifier, int $amountMinor, string $idempotencyKey): WalletTransfer
    {
        if ($amountMinor < 1 || $amountMinor > 500000000) {
            throw new InvalidArgumentException('Transfer amount is outside the permitted range.');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('A valid idempotency key is required.');
        }

        $recipient = User::query()
            ->where('username', $recipientIdentifier)
            ->orWhere('email', $recipientIdentifier)
            ->first();

        if (! $recipient) {
            throw new InvalidArgumentException('Recipient account could not be found.');
        }
        if ($recipient->is($sender)) {
            throw new InvalidArgumentException('You cannot transfer funds to your own account.');
        }

        $requestHash = hash('sha256', json_encode([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount_minor' => $amountMinor,
            'currency' => 'NGN',
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($sender, $recipient, $amountMinor, $idempotencyKey, $requestHash): WalletTransfer {
            $existing = WalletTransfer::query()
                ->where('sender_user_id', $sender->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new IdempotencyConflict('This transfer key was already used for a different request.');
                }
                return $existing;
            }

            $senderWallet = $this->wallets->openForUser($sender, 'NGN');
            $recipientWallet = $this->wallets->openForUser($recipient, 'NGN');
            $walletIds = [$senderWallet->id, $recipientWallet->id];
            sort($walletIds);
            Wallet::query()->whereIn('id', $walletIds)->orderBy('id')->lockForUpdate()->get();

            $transferId = (string) Str::uuid();
            $senderTransaction = $this->ledger->post(
                walletId: $senderWallet->id,
                type: 'debit',
                amountMinor: $amountMinor,
                idempotencyKey: 'p2p:'.$transferId.':debit',
                reference: $transferId,
                description: 'P2P transfer to '.$recipient->username,
                actorUserId: $sender->id,
                metadata: ['transfer_id' => $transferId, 'direction' => 'outgoing'],
            );
            $recipientTransaction = $this->ledger->post(
                walletId: $recipientWallet->id,
                type: 'credit',
                amountMinor: $amountMinor,
                idempotencyKey: 'p2p:'.$transferId.':credit',
                reference: $transferId,
                description: 'P2P transfer received',
                actorUserId: $sender->id,
                metadata: ['transfer_id' => $transferId, 'direction' => 'incoming', 'sender_user_id' => $sender->id],
            );

            return WalletTransfer::query()->create([
                'public_id' => $transferId,
                'sender_user_id' => $sender->id,
                'recipient_user_id' => $recipient->id,
                'currency' => 'NGN',
                'amount_minor' => $amountMinor,
                'status' => 'posted',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'sender_transaction_id' => $senderTransaction->id,
                'recipient_transaction_id' => $recipientTransaction->id,
                'description' => 'P2P wallet transfer',
            ]);
        }, attempts: 3);
    }
}
