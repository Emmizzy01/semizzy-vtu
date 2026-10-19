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
        $airDataSetting = app(IntegrationSettings::class)->get('service.airtime_data.enabled', null);
        if ((! isset($validated['service_type']) || in_array($validated['service_type'], ['airtime', 'data'], true))
            && $airDataSetting !== null && ! filter_var($airDataSetting, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(['message' => 'Airtime and data purchases are temporarily unavailable.'], 503);
        }

        $serviceControls = PlatformFeatureControl::query()
            ->whereIn('feature_key', ['service.airtime_data', 'service.electricity', 'service.tv'])
            ->get(['feature_key', 'is_enabled', 'is_implemented'])
            ->keyBy('feature_key');

        $products = VtuProduct::query()
            ->where('currency', 'NGN')
            ->when(isset($validated['service_type']), fn ($query) => $query->where('service_type', $validated['service_type']))
            ->when(isset($validated['network']), fn ($query) => $query->where('network', $validated['network']))
            ->orderBy('service_type')
            ->orderBy('network')
            ->orderBy('price_minor')
            ->get(['id', 'code', 'service_type', 'network', 'name', 'price_minor', 'face_value_minor', 'currency', 'metadata', 'is_active'])
            ->filter(function (VtuProduct $product): bool {
                if ($product->is_active) {
                    return in_array($product->service_type, ['airtime', 'data'], true)
                        || ($product->metadata['purchase_supported'] ?? false) === true;
                }

                // Let customers see mapped VTpass airtime/data catalogue entries
                // before sales are enabled, while purchase() still rejects inactive
                // products server-side. Unmapped drafts remain admin-only.
                $metadata = $product->metadata ?? [];
                $legacyAirtimeDenomination = $product->service_type === 'airtime'
                    && in_array((string) ($metadata['catalog_source'] ?? ''), ['sogo', 'gsubz', 'dataway'], true)
                    && preg_match('/-\\d{2,}$/', $product->code) === 1;
                if ($legacyAirtimeDenomination) {
                    return false;
                }
                $hasProviderService = is_string($metadata['provider_service_id'] ?? null)
                    && $metadata['provider_service_id'] !== '';
                $hasDataVariation = is_string($metadata['provider_variation_code'] ?? null)
                    && $metadata['provider_variation_code'] !== '';

                return in_array($product->service_type, ['airtime', 'data'], true)
                    && $hasProviderService
                    && ($product->service_type === 'airtime' || $hasDataVariation);
            })
            ->map(function (VtuProduct $product) use ($serviceControls, $airDataSetting): VtuProduct {
                // Reflect the actual provider purchase gate in the customer UI,
                // even for legacy rows that were active before approval tracking.
                if (($product->metadata['catalog_source'] ?? null) === 'vtpass'
                    && ($product->metadata['purchase_approved'] ?? false) !== true) {
                    $product->setAttribute('is_active', false);
                }

                $featureKey = match ($product->service_type) {
                    'airtime', 'data' => 'service.airtime_data',
                    'electricity-bill' => 'service.electricity',
                    'tv-subscription' => 'service.tv',
                    default => null,
                };
                if ($featureKey !== null) {
                    $control = $serviceControls->get($featureKey);
                    if ($control === null || ! $control->is_enabled || ! $control->is_implemented) {
                        $product->setAttribute('is_active', false);
                    }
                    if ($featureKey === 'service.airtime_data'
                        && ($airDataSetting === null || ! filter_var($airDataSetting, FILTER_VALIDATE_BOOLEAN))) {
                        $product->setAttribute('is_active', false);
                    }
                }

                return $product;
            })
            ->values();

        // Return only customer-facing catalogue fields. Provider identity, provider IDs,
        // source metadata, and internal routing details stay on the server.
        $customerProducts = $products->map(function (VtuProduct $product): array {
            $metadata = $product->metadata ?? [];
            $publicMetadata = array_intersect_key($metadata, array_flip([
                'validity', 'data_type', 'amount_based',
                'provider_minimum_amount', 'provider_maximum_amount',
            ]));

            return [
                'code' => $this->customerSelectionCode($product), // signed opaque key; never exposes provider-prefixed internal codes
                'service_type' => $product->service_type,
                'network' => $product->network,
                'name' => $product->name,
                'price_minor' => (int) $product->price_minor,
                'face_value_minor' => $product->face_value_minor,
                'currency' => $product->currency,
                'metadata' => $publicMetadata,
                'is_active' => (bool) $product->is_active,
            ];
        })->values();

        return response()->json(['data' => $customerProducts]);
    }

    public function purchase(Request $request, VtuOrderService $orders): JsonResponse
    {
        $validated = $request->validate([
            'product_code' => ['required', 'string', 'max:80'],
            'storefront_slug' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'recipient' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9+\\s-]+$/'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'transaction_pin' => ['nullable', 'string', 'digits:4'],
            'meter_type' => ['nullable', 'string', 'in:prepaid,postpaid'],
            'subscription_type' => ['nullable', 'string', 'in:change'],
            'contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^\\+?[0-9\\s-]{10,20}$/'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
        ]);

        $selectedProduct = $this->resolveCustomerSelectionCode($validated['product_code']);
        $productType = $selectedProduct?->service_type;
        if ($productType === 'airtime' && ! isset($validated['amount'])) {
            return response()->json(['message' => 'Enter the airtime amount you want to purchase.'], 422);
        }
        $user = $request->user();
        abort_unless($user, 401);
        if (! $user->transaction_pin_hash) return response()->json(['message' => 'Set up a transaction PIN before making wallet-funded purchases.', 'setup_url' => '/security/transaction-pin'], 428);
        if (! isset($validated['transaction_pin'])) return response()->json(['message' => 'Enter your four-digit transaction PIN.'], 422);
        $pinStatus = app(TransactionPinService::class)->verify($user, (string) $validated['transaction_pin']);
        if ($pinStatus === 'locked') return response()->json(['message' => 'Transaction PIN is locked for 15 minutes after repeated failures.'], 423);
        if ($pinStatus !== 'verified') return response()->json(['message' => 'Transaction PIN is incorrect.'], 422);

        if (in_array($productType, ['airtime', 'data'], true)) {
            $airDataFeature = PlatformFeatureControl::query()->where('feature_key', 'service.airtime_data')->first(['is_enabled', 'is_implemented']);
            if ($airDataFeature === null || ! $airDataFeature->is_enabled || ! $airDataFeature->is_implemented
                || ! filter_var(app(IntegrationSettings::class)->get('service.airtime_data.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
                return response()->json(['message' => 'Airtime and data purchases are temporarily unavailable.'], 503);
            }
        }

        try {
            $order = $orders->purchase(
                $user,
                $selectedProduct?->code ?? $validated['product_code'],
                $validated['recipient'],
                $validated['idempotency_key'],
                array_filter([
                    'meter_type' => $validated['meter_type'] ?? null,
                    'subscription_type' => $validated['subscription_type'] ?? null,
                    'contact_phone' => $validated['contact_phone'] ?? null,
                ], fn ($value) => $value !== null),
                isset($validated['amount']) ? (int) round((float) $validated['amount'] * 100) : null,
                $validated['storefront_slug'] ?? null,
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

    /**
     * Process a small batch as independent idempotent orders. Each item keeps
     * the existing order service's wallet, provider approval and reconciliation
     * safeguards; a partial batch is reported per item and is never auto-retried.
     */
    public function bulkPurchase(Request $request, VtuOrderService $orders): JsonResponse
    {
        $validated = $request->validate([
            'batch_key' => ['required', 'string', 'min:8', 'max:128'],
            'transaction_pin' => ['required', 'string', 'digits:4'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.product_code' => ['required', 'string', 'max:80'],
            'items.*.recipient' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9+\\s-]+$/'],
            'items.*.amount' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'items.*.meter_type' => ['nullable', 'string', 'in:prepaid,postpaid'],
            'items.*.subscription_type' => ['nullable', 'string', 'in:change'],
            'items.*.contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^\\+?[0-9\\s-]{10,20}$/'],
        ]);

        $user = $request->user();
        abort_unless($user, 401);
        if (! $user->transaction_pin_hash) {
            return response()->json(['message' => 'Set up a transaction PIN before making wallet-funded purchases.', 'setup_url' => '/security/transaction-pin'], 428);
        }
        $pinStatus = app(TransactionPinService::class)->verify($user, (string) $validated['transaction_pin']);
        if ($pinStatus === 'locked') {
            return response()->json(['message' => 'Transaction PIN is locked for 15 minutes after repeated failures.'], 423);
        }
        if ($pinStatus !== 'verified') {
            return response()->json(['message' => 'Transaction PIN is incorrect.'], 422);
        }

        $results = [];
        foreach ($validated['items'] as $index => $item) {
            $selection = $this->resolveCustomerSelectionCode($item['product_code']);
            if ($selection === null) {
                $results[] = ['item' => $index, 'status' => 'rejected', 'message' => 'This product selection is invalid.'];
                continue;
            }
            if ($selection->service_type === 'airtime' && ! isset($item['amount'])) {
                $results[] = ['item' => $index, 'status' => 'rejected', 'message' => 'Enter the airtime amount you want to purchase.'];
                continue;
            }

            try {
                $order = $orders->purchase(
                    $user,
                    $selection->code,
                    $item['recipient'],
                    hash('sha256', $validated['batch_key'].'|item:'.$index),
                    array_filter([
                        'meter_type' => $item['meter_type'] ?? null,
                        'subscription_type' => $item['subscription_type'] ?? null,
                        'contact_phone' => $item['contact_phone'] ?? null,
                    ], fn ($value) => $value !== null),
                    isset($item['amount']) ? (int) round((float) $item['amount'] * 100) : null,
                );
                $results[] = [
                    'item' => $index,
                    'status' => $order->status,
                    'order' => $this->serializeOrder($order),
                ];
            } catch (InvalidArgumentException|InsufficientWalletFunds|ConflictHttpException $exception) {
                $results[] = ['item' => $index, 'status' => 'rejected', 'message' => $exception->getMessage()];
            } catch (LogicException $exception) {
                report($exception);
                $results[] = ['item' => $index, 'status' => 'unavailable', 'message' => 'This item could not be submitted because its service is not configured.'];
            }
        }

        $hasRejected = collect($results)->contains(fn (array $result) => in_array($result['status'], ['rejected', 'unavailable'], true));
        return response()->json([
            'batch_key' => $validated['batch_key'],
            'results' => $results,
            'partial' => $hasRejected,
            'message' => 'Items are processed independently. Check each item status before attempting any further action.',
        ], 200);
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
    /** Return a stable, signed selection key without exposing provider-prefixed catalogue codes. */
    private function customerSelectionCode(VtuProduct $product): string
    {
        $id = (string) $product->getKey();
        $signature = substr(hash_hmac('sha256', 'vtu-product:'.$id, (string) config('app.key')), 0, 32);

        return 'p'.base_convert($id, 10, 36).'.'.$signature;
    }

    /** Resolve a signed public selection key; legacy internal codes remain accepted for compatibility. */
    private function resolveCustomerSelectionCode(string $code): ?VtuProduct
    {
        if (preg_match('/^p([0-9a-z]+)\.([a-f0-9]{32})$/D', $code, $matches) === 1) {
            $id = base_convert($matches[1], 36, 10);
            if (! ctype_digit($id) || (int) $id < 1) {
                return null;
            }

            $expected = substr(hash_hmac('sha256', 'vtu-product:'.$id, (string) config('app.key')), 0, 32);
            if (! hash_equals($expected, $matches[2])) {
                return null;
            }

            return VtuProduct::query()->find((int) $id);
        }

        return VtuProduct::query()->where('code', $code)->first();
    }

    private function serializeOrder(VtuOrder $order): array
    {
        return [
            'id' => $order->public_id,
            'service_type' => $order->service_type,
            'network' => $order->network,
            'product_code' => $order->product instanceof VtuProduct ? $this->customerSelectionCode($order->product) : null,
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
