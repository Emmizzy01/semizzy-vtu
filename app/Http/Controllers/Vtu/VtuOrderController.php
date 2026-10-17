<?php

namespace App\Http\Controllers\Vtu;

use App\Http\Controllers\Controller;
use App\Exceptions\Wallet\InsufficientWalletFunds;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Models\PlatformFeatureControl;
use App\Services\Vtu\VtuOrderService;
use App\Services\IntegrationSettings;
use App\Services\Security\TransactionPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VtuOrderController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['sometimes', 'string', 'in:airtime,data,electricity-bill,tv-subscription'],
            'network' => ['sometimes', 'string', 'max:40'],
        ]);
        if ((! isset($validated['service_type']) || in_array($validated['service_type'], ['airtime', 'data'], true))
            && ! filter_var(app(IntegrationSettings::class)->get('service.airtime_data.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(['message' => 'Airtime and data purchases are temporarily unavailable.'], 503);
        }

        $serviceControls = PlatformFeatureControl::query()
            ->whereIn('feature_key', ['service.electricity', 'service.tv'])
            ->get(['feature_key', 'is_enabled', 'is_implemented'])
            ->keyBy('feature_key');

        $products = VtuProduct::query()
            ->where('currency', 'NGN')
            ->when(isset($validated['service_type']), fn ($query) => $query->where('service_type', $validated['service_type']))
            ->when(isset($validated['network']), fn ($query) => $query->where('network', $validated['network']))
            ->orderBy('service_type')
            ->orderBy('network')
            ->orderBy('price_minor')
            ->get(['code', 'service_type', 'network', 'name', 'price_minor', 'face_value_minor', 'currency', 'metadata', 'is_active'])
            ->filter(function (VtuProduct $product): bool {
                if ($product->is_active) {
                    return in_array($product->service_type, ['airtime', 'data'], true)
                        || ($product->metadata['purchase_supported'] ?? false) === true;
                }

                // Let customers see mapped VTpass airtime/data catalogue entries
                // before sales are enabled, while purchase() still rejects inactive
                // products server-side. Unmapped drafts remain admin-only.
                $metadata = $product->metadata ?? [];
                $hasProviderService = is_string($metadata['provider_service_id'] ?? null)
                    && $metadata['provider_service_id'] !== '';
                $hasDataVariation = is_string($metadata['provider_variation_code'] ?? null)
                    && $metadata['provider_variation_code'] !== '';

                return in_array($product->service_type, ['airtime', 'data'], true)
                    && $hasProviderService
                    && ($product->service_type === 'airtime' || $hasDataVariation);
            })
            ->map(function (VtuProduct $product) use ($serviceControls): VtuProduct {
                // Reflect the actual provider purchase gate in the customer UI,
                // even for legacy rows that were active before approval tracking.
                if (($product->metadata['catalog_source'] ?? null) === 'vtpass'
                    && ($product->metadata['purchase_approved'] ?? false) !== true) {
                    $product->setAttribute('is_active', false);
                }

                $featureKey = match ($product->service_type) {
                    'electricity-bill' => 'service.electricity',
                    'tv-subscription' => 'service.tv',
                    default => null,
                };
                if ($featureKey !== null) {
                    $control = $serviceControls->get($featureKey);
                    if ($control === null || ! $control->is_enabled || ! $control->is_implemented) {
                        $product->setAttribute('is_active', false);
                    }
                }

                return $product;
            })
            ->values();

        return response()->json(['data' => $products]);
    }

    public function purchase(Request $request, VtuOrderService $orders): JsonResponse
    {
        $validated = $request->validate([
            'product_code' => ['required', 'string', 'max:80'],
            'recipient' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9+\\s-]+$/'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'transaction_pin' => ['nullable', 'string', 'digits:4'],
            'meter_type' => ['nullable', 'string', 'in:prepaid,postpaid'],
            'subscription_type' => ['nullable', 'string', 'in:change'],
            'contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^\\+?[0-9\\s-]{10,20}$/'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
        ]);

        $productType = VtuProduct::query()->where('code', $validated['product_code'])->value('service_type');
        if ($productType === 'airtime' && ! isset($validated['amount'])) {
            return response()->json(['message' => 'Enter the airtime amount you want to purchase.'], 422);
        }
        if (in_array($productType, ['airtime', 'data'], true)
            && ! filter_var(app(IntegrationSettings::class)->get('service.airtime_data.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(['message' => 'Airtime and data purchases are temporarily unavailable.'], 503);
        }

        $user = $request->user();
        abort_unless($user, 401);
        if (! $user->transaction_pin_hash) return response()->json(['message' => 'Set up a transaction PIN before making wallet-funded purchases.', 'setup_url' => '/security/transaction-pin'], 428);
        if (! isset($validated['transaction_pin'])) return response()->json(['message' => 'Enter your four-digit transaction PIN.'], 422);
        $pinStatus = app(TransactionPinService::class)->verify($user, (string) $validated['transaction_pin']);
        if ($pinStatus === 'locked') return response()->json(['message' => 'Transaction PIN is locked for 15 minutes after repeated failures.'], 423);
        if ($pinStatus !== 'verified') return response()->json(['message' => 'Transaction PIN is incorrect.'], 422);

        try {
            $order = $orders->purchase(
                $user,
                $validated['product_code'],
                $validated['recipient'],
                $validated['idempotency_key'],
                array_filter([
                    'meter_type' => $validated['meter_type'] ?? null,
                    'subscription_type' => $validated['subscription_type'] ?? null,
                    'contact_phone' => $validated['contact_phone'] ?? null,
                ], fn ($value) => $value !== null),
                isset($validated['amount']) ? (int) round((float) $validated['amount'] * 100) : null,
            );
        } catch (InvalidArgumentException|InsufficientWalletFunds $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (LogicException $exception) {
            report($exception);

            return response()->json([
                'message' => 'VTU purchasing is not configured yet. No order was submitted.',
            ], 503);
        } catch (ConflictHttpException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->serializeOrder($order)], $order->getAttribute('_created_for_request') === true ? 201 : 200);
    }

    public function recover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);
        $user = $request->user();
        abort_unless($user, 401);

        $order = VtuOrder::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($order === null) {
            return response()->json([
                'data' => null,
                'message' => 'No saved order is visible yet. Keep this request unchanged and check again before starting another purchase.',
            ], 404);
        }

        return response()->json(['data' => $this->serializeOrder($order)]);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $order = VtuOrder::query()
            ->where('public_id', $publicId)
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        return response()->json(['data' => $this->serializeOrder($order)]);
    }

    /** @return array<string, mixed> */
    private function serializeOrder(VtuOrder $order): array
    {
        return [
            'id' => $order->public_id,
            'service_type' => $order->service_type,
            'network' => $order->network,
            'product_code' => $order->product_code,
            'recipient' => $order->recipient,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'status' => $order->status,
            'provider_reference' => $order->provider_reference,
            'failure_code' => $order->failure_code,
            'created_at' => $order->created_at?->toISOString(),
            'completed_at' => $order->completed_at?->toISOString(),
        ];
    }
}
