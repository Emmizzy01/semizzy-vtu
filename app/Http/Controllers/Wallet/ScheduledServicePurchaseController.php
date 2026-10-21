<?php

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Controller;
use App\Models\ScheduledServicePurchase;
use App\Models\User;
use App\Models\VtuProduct;
use App\Services\Security\TransactionPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ScheduledServicePurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = ScheduledServicePurchase::query()->where('user_id', $request->user()->getKey())
            ->orderBy('next_run_at')->get([
                'id', 'service_type', 'product_code', 'recipient', 'amount_minor', 'currency',
                'service_details', 'frequency', 'next_run_at', 'last_run_at', 'status',
                'last_order_public_id', 'last_result', 'last_error',
            ]);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, TransactionPinService $pins): JsonResponse
    {
        $request->merge([
            'idempotency_key' => $request->input('idempotency_key', $request->header('Idempotency-Key')),
        ]);
        $validated = $request->validate([
            'service_type' => ['required', Rule::in(['airtime', 'data', 'electricity-bill', 'tv-subscription'])],
            'product_code' => ['required', 'string', 'max:100'],
            'recipient' => ['required', 'string', 'min:5', 'max:40', 'regex:/^[a-zA-Z0-9+\s-]+$/'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'next_run_at' => ['required', 'date', 'after:now'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'transaction_pin' => ['required', 'digits:4'],
            'meter_type' => ['nullable', Rule::in(['prepaid', 'postpaid'])],
            'contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s-]{10,20}$/'],
            'subscription_type' => ['nullable', Rule::in(['change'])],
        ]);

        $pinResult = $pins->verify($request->user(), (string) $validated['transaction_pin']);
        if ($pinResult !== 'verified') {
            return response()->json([
                'message' => $pinResult === 'locked'
                    ? 'Transaction PIN is locked for 15 minutes.'
                    : 'Transaction PIN is incorrect or not configured.',
            ], $pinResult === 'locked' ? 423 : 422);
        }

        $product = $this->resolveProduct((string) $validated['product_code']);
        if (!$product || !$product->is_active || $product->service_type !== $validated['service_type']
            || $product->currency !== 'NGN') {
            return response()->json(['message' => 'Choose an active product that supports scheduled purchases.'], 422);
        }
        if (!in_array($product->service_type, ['airtime', 'data', 'electricity-bill', 'tv-subscription'], true)) {
            return response()->json(['message' => 'This service does not yet have a verified scheduled-purchase handler.'], 422);
        }

        $recipient = preg_replace('/[\s-]+/', '', trim($validated['recipient'])) ?? '';
        $details = [];
        if ($product->service_type === 'electricity-bill') {
            if (!in_array($validated['meter_type'] ?? null, ['prepaid', 'postpaid'], true)) {
                return response()->json(['message' => 'Select the meter type.'], 422);
            }
            $details['meter_type'] = $validated['meter_type'];
        } elseif ($product->service_type === 'tv-subscription') {
            $details['subscription_type'] = 'change';
        }
        if (isset($validated['contact_phone'])) {
            $details['contact_phone'] = preg_replace('/[\s-]+/', '', $validated['contact_phone']);
        }

        $amountMinor = isset($validated['amount']) ? (int) round((float) $validated['amount'] * 100) : null;
        if ($product->service_type === 'airtime' && ($amountMinor === null || $amountMinor % 100 !== 0)) {
            return response()->json(['message' => 'Enter a whole-naira airtime amount.'], 422);
        }
        if ($product->service_type === 'electricity-bill' && $amountMinor === null) {
            return response()->json(['message' => 'Enter the electricity amount.'], 422);
        }

        $requestHash = hash('sha256', json_encode([
            'service_type' => $product->service_type,
            'product_code' => $product->code,
            'recipient' => $recipient,
            'amount_minor' => $amountMinor,
            'currency' => 'NGN',
            'service_details' => $details ?: null,
            'frequency' => $validated['frequency'],
            'next_run_at' => Carbon::parse($validated['next_run_at'])->utc()->toISOString(),
        ], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($request, $product, $recipient, $amountMinor, $details, $validated, $requestHash): array {
            // Serialize schedule creation per account so simultaneous retries cannot
            // create two future debit schedules for the same idempotency key.
            User::query()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();

            $existing = ScheduledServicePurchase::query()
                ->where('user_id', $request->user()->getKey())
                ->where('idempotency_key', $validated['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $sameRequest = hash_equals((string) $existing->request_hash, $requestHash);

                return [
                    'schedule' => $existing,
                    'replayed' => $sameRequest,
                    'conflict' => ! $sameRequest,
                ];
            }

            $schedule = ScheduledServicePurchase::query()->create([
                'user_id' => $request->user()->getKey(),
                'service_type' => $product->service_type,
                'product_code' => $product->code,
                'recipient' => $recipient,
                'amount_minor' => $amountMinor,
                'currency' => 'NGN',
                'service_details' => $details ?: null,
                'frequency' => $validated['frequency'],
                'next_run_at' => $validated['next_run_at'],
                'status' => 'active',
                'idempotency_key' => $validated['idempotency_key'],
                'request_hash' => $requestHash,
            ]);

            return ['schedule' => $schedule, 'replayed' => false, 'conflict' => false];
        }, attempts: 3);

        if ($result['conflict']) {
            return response()->json(['message' => 'This idempotency key was already used for a different scheduled purchase.'], 409);
        }

        return response()->json(['data' => $result['schedule']], $result['replayed'] ? 200 : 201);
    }

    public function destroy(Request $request, int $schedule): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $schedule): array {
            // Serialize cancellation with the scheduler's active -> processing
            // claim. Without this row lock, a concurrent cancel can overwrite a
            // processing claim and falsely tell the user the run was cancelled.
            $item = ScheduledServicePurchase::query()
                ->where('user_id', $request->user()->getKey())
                ->whereKey($schedule)
                ->lockForUpdate()
                ->firstOrFail();

            if ($item->status === 'processing') {
                return ['processing' => true];
            }

            $item->update(['status' => 'cancelled']);

            return ['processing' => false];
        });

        if ($result['processing']) {
            return response()->json(['message' => 'This scheduled purchase is processing and cannot be cancelled until its outcome is recorded.'], 409);
        }

        return response()->json(['message' => 'Scheduled purchase cancelled.']);
    }

    private function resolveProduct(string $code): ?VtuProduct
    {
        if (preg_match('/^p([0-9a-z]+)\.([a-f0-9]{32})$/D', $code, $matches) === 1) {
            $id = base_convert($matches[1], 36, 10);
            if (!ctype_digit($id) || (int) $id < 1) return null;
            $expected = substr(hash_hmac('sha256', 'vtu-product:'.$id, (string) config('app.key')), 0, 32);
            if (!hash_equals($expected, $matches[2])) return null;
            return VtuProduct::query()->find((int) $id);
        }
        return VtuProduct::query()->where('code', $code)->first();
    }
}
