<?php

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Controller;
use App\Models\ServiceBeneficiary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;

class ServiceBeneficiaryController extends Controller
{
    private const SERVICE_TYPES = [
        'airtime', 'data', 'electricity-bill', 'tv-subscription',
        'streaming', 'education', 'government-service', 'number-verification',
        'social-media', 'smm', 'betting',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['sometimes', 'string', Rule::in(self::SERVICE_TYPES)],
        ]);

        $items = ServiceBeneficiary::query()
            ->where('user_id', $request->user()->getKey())
            ->when(isset($validated['service_type']), fn ($query) => $query->where('service_type', $validated['service_type']))
            ->orderBy('service_type')->orderBy('label')
            ->get(['id', 'service_type', 'label', 'recipient', 'network', 'product_code', 'service_details', 'created_at']);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['required', 'string', Rule::in(self::SERVICE_TYPES)],
            'label' => ['required', 'string', 'min:1', 'max:80'],
            'recipient' => ['required', 'string', 'min:5', 'max:40', 'regex:/^[a-zA-Z0-9+\s-]+$/'],
            'network' => ['nullable', 'string', 'max:50'],
            'product_code' => ['nullable', 'string', 'max:100'],
            'service_details' => ['nullable', 'array'],
            'service_details.meter_type' => ['nullable', Rule::in(['prepaid', 'postpaid'])],
        ]);

        $recipient = preg_replace('/[\s-]+/', '', trim($validated['recipient'])) ?? '';
        $details = $validated['service_details'] ?? [];
        // Store only fields that are safe to reuse; never accept PINs or payment data.
        $details = array_intersect_key($details, array_flip(['meter_type', 'subscription_type']));

        $existing = ServiceBeneficiary::query()
            ->where('user_id', $request->user()->getKey())
            ->where('service_type', $validated['service_type'])
            ->where('label', trim($validated['label']))
            ->first();

        if ($existing) {
            return response()->json(['message' => 'A beneficiary with this label already exists for this service.'], 409);
        }

        try {
            $beneficiary = ServiceBeneficiary::query()->create([
                'user_id' => $request->user()->getKey(),
                'service_type' => $validated['service_type'],
                'label' => trim($validated['label']),
                'recipient' => $recipient,
                'network' => isset($validated['network']) ? trim($validated['network']) : null,
                'product_code' => $validated['product_code'] ?? null,
                'service_details' => $details ?: null,
            ]);
        } catch (QueryException $exception) {
            // The unique database constraint is the final authority when two
            // identical submissions race past the friendly pre-check above.
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                return response()->json(['message' => 'A beneficiary with this label already exists for this service.'], 409);
            }

            throw $exception;
        }

        return response()->json(['data' => $beneficiary->only([
            'id', 'service_type', 'label', 'recipient', 'network', 'product_code', 'service_details', 'created_at',
        ])], 201);
    }

    public function destroy(Request $request, int $beneficiary): JsonResponse
    {
        $record = ServiceBeneficiary::query()
            ->where('user_id', $request->user()->getKey())
            ->whereKey($beneficiary)
            ->firstOrFail();

        $record->delete();

        return response()->json(['message' => 'Beneficiary removed.']);
    }
}
