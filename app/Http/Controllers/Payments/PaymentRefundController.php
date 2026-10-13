<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentRefund;
use App\Services\Payments\PaymentRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;

class PaymentRefundController extends Controller
{
    public function dashboard(): InertiaResponse
    {
        return Inertia::render('Admin/Refunds/Index');
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:requested,processing,provider_pending,succeeded,failed,blocked_insufficient_wallet_funds,review_required'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PaymentRefund::query()->with(['paymentIntent', 'requester'])->latest('id');
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (PaymentRefund $refund): array => $this->present($refund))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, string $refund): JsonResponse
    {
        $record = PaymentRefund::query()
            ->with('paymentIntent')
            ->where('public_id', $refund)
            ->whereHas('paymentIntent.wallet', fn ($query) => $query->where('user_id', $request->user()->id))
            ->firstOrFail();

        return response()->json(['data' => $this->present($record)]);
    }

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
            'payment_reference' => $refund->paymentIntent()->value('reference'),
            'requested_by_user_id' => $refund->requested_by_user_id,
            'approved_by_user_id' => $refund->approved_by_user_id,
            'amount_minor' => $refund->amount_minor,
            'currency' => $refund->metadata['currency'] ?? $refund->paymentIntent()->value('currency'),
            'reason' => $refund->reason,
            'status' => $refund->status,
            'provider_status' => $refund->provider_status,
            'provider_refund_reference' => $refund->provider_refund_reference,
            'requested_at' => $refund->created_at?->toISOString(),
            'approved_at' => $refund->approved_at?->toISOString(),
            'settled_at' => $refund->settled_at?->toISOString(),
        ];
    }
}
