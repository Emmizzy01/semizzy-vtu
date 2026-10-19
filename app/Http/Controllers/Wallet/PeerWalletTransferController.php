<?php

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Controller;
use App\Models\WalletTransfer;
use App\Services\Security\TransactionPinService;
use App\Services\Wallets\PeerWalletTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PeerWalletTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $transfers = WalletTransfer::query()
            ->where(fn ($q) => $q->where('sender_user_id', $userId)->orWhere('recipient_user_id', $userId))
            ->latest('id')->paginate(25);

        return response()->json([
            'data' => $transfers->getCollection()->map(fn (WalletTransfer $transfer) => $this->present($transfer, $userId)),
            'meta' => ['current_page' => $transfers->currentPage(), 'last_page' => $transfers->lastPage(), 'total' => $transfers->total()],
        ]);
    }

    public function store(Request $request, TransactionPinService $pins, PeerWalletTransferService $transfers): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'string', 'min:3', 'max:190'],
            'amount_minor' => ['required', 'integer', 'min:100', 'max:500000000'],
            'transaction_pin' => ['required', 'string', 'digits:4'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        $pinStatus = $pins->verify($request->user(), $data['transaction_pin']);
        if ($pinStatus !== 'verified') {
            return response()->json(['message' => match ($pinStatus) {
                'not_configured' => 'Set up your transaction PIN before transferring funds.',
                'locked' => 'Transaction PIN is temporarily locked. Try again later.',
                default => 'Transaction PIN is incorrect.',
            }], 422);
        }

        try {
            $transfer = $transfers->transfer(
                $request->user(),
                $data['recipient'],
                (int) $data['amount_minor'],
                $data['idempotency_key'],
            );
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($transfer, $request->user()->id)], 201);
    }

    private function present(WalletTransfer $transfer, int $viewerId): array
    {
        return [
            'id' => $transfer->public_id,
            'direction' => $transfer->sender_user_id === $viewerId ? 'sent' : 'received',
            'recipient' => $transfer->sender_user_id === $viewerId ? $transfer->recipient?->username : null,
            'sender' => $transfer->recipient_user_id === $viewerId ? $transfer->sender?->username : null,
            'amount_minor' => $transfer->amount_minor,
            'currency' => $transfer->currency,
            'status' => $transfer->status,
            'created_at' => $transfer->created_at?->toISOString(),
        ];
    }
}
