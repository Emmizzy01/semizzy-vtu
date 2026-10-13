<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentRefundController extends Controller
{
    public function requestRefund(Request $request, PaymentRefundService $refunds): JsonResponse
    {
        $validated = $request->validate([
            'payment_intent_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:500000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $key = $validated['idempotency_key'] ?? $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '' || strlen($key) > 128) {
            return response()->json(['message' => 'An Idempotency-Key header or idempotency_key is required.'], 422);
        }

        try {
            $refund = $refunds->request(
                $request->user(),
                $validated['payment_intent_id'],
                (int) $validated['amount_minor'],
                $validated['reason'],
                $key,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($refund)], 201);
    }

    public function approveRefund(Request $request, string $refund, PaymentRefundService $refunds): JsonResponse
    {
        $approved = $refunds->approve($refund, $request->user());
        $status = in_array($approved->status, ['blocked_insufficient_wallet_funds', 'review_required'], true) ? 409 : 200;

        return response()->json(['data' => $this->present($approved)], $status);
    }

    private function present(\App\Models\PaymentRefund $refund): array
    {
        return [
            'id' => $refund->public_id,
            'payment_intent_id' => $refund->paymentIntent()->value('public_id'),
            'amount_minor' => $refund->amount_minor,
            'currency' => $refund->metadata['currency'] ?? $refund->paymentIntent()->value('currency'),
            'reason' => $refund->reason,
            'status' => $refund->status,
            'provider_status' => $refund->provider_status,
            'provider_refund_reference' => $refund->provider_refund_reference,
            'approved_at' => $refund->approved_at?->toISOString(),
            'settled_at' => $refund->settled_at?->toISOString(),
        ];
    }
}
