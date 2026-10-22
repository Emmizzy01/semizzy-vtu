<?php

namespace App\Http\Controllers\Vtu;

use App\Http\Controllers\Controller;
use App\Exceptions\Wallet\InsufficientWalletFunds;
use App\Models\VtuOrder;
use App\Models\VtuBulkPurchaseBatch;
use App\Models\VtuBulkPurchaseItem;
use App\Jobs\ProcessVtuBulkPurchaseBatch;
use App\Models\VtuProduct;
use App\Models\PlatformFeatureControl;
use App\Models\PlatformAddon;
use App\Services\Vtu\VtuOrderService;
use App\Services\IntegrationSettings;
use App\Services\Security\TransactionPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
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

        $addonControls = PlatformAddon::query()
            ->whereIn('addon_key', ['service.airtime', 'service.data', 'service.electricity', 'service.tv'])
            ->get(['addon_key', 'status', 'compatibility_status'])
            ->keyBy('addon_key');

        $products = VtuProduct::query()
            ->where('currency', 'NGN')
            ->when(isset($validated['service_type']), fn ($query) => $query->where('service_type', $validated['service_type']))
            ->when(isset($validated['network']), fn ($query) => $query->where('network', $validated['network']))
            ->orderBy('service_type')
            ->orderBy('network')
            ->orderBy('price_minor')
            ->get(['id', 'code', 'service_type', 'provider_key', 'network', 'name', 'price_minor', 'face_value_minor', 'currency', 'metadata', 'is_active'])
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
                // Multi-provider imports store their identifiers under the provider-specific
                // mapping rather than copying internal routing IDs into the public metadata.
                // Recognise those verified mapping shapes here so imported offers are visible
                // as separate customer options, while purchase() still enforces active/approval gates.
                $provider = $product->provider_key;
                $providerMapping = is_string($provider)
                    ? data_get($metadata, 'provider_mappings.'.$provider, [])
                    : [];
                $hasProviderService = (is_string($metadata['provider_service_id'] ?? null)
                        && trim($metadata['provider_service_id']) !== '')
                    || (is_array($providerMapping) && collect([
                        $providerMapping['network'] ?? null,
                        $providerMapping['service_id'] ?? null,
                        $providerMapping['product_id'] ?? null,
                    ])->contains(fn ($value): bool => is_string($value) && trim($value) !== ''));
                $hasDataVariation = (is_string($metadata['provider_variation_code'] ?? null)
                        && trim($metadata['provider_variation_code']) !== '')
                    || (is_array($providerMapping) && collect([
                        $providerMapping['variation_code'] ?? null,
                        $providerMapping['plan_code'] ?? null,
                        $providerMapping['product_id'] ?? null,
                    ])->contains(fn ($value): bool => is_string($value) && trim($value) !== ''));

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

                $addonKey = match ($product->service_type) {
                    'airtime' => 'service.airtime',
                    'data' => 'service.data',
                    'electricity-bill' => 'service.electricity',
                    'tv-subscription' => 'service.tv',
                    default => null,
                };
                $addon = $addonKey !== null ? $addonControls->get($addonKey) : null;
                if ($addonKey !== null && ($addon === null || $addon->status !== 'enabled' || $addon->compatibility_status !== 'compatible')) {
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
            'items' => ['required', 'array', 'min:10', 'max:100'],
            'items.*.product_code' => ['required', 'string', 'max:80'],
            'items.*.recipient' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9+\\s-]+$/'],
            'items.*.amount' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'items.*.meter_type' => ['nullable', 'string', 'in:prepaid,postpaid'],
            'items.*.subscription_type' => ['nullable', 'string', 'in:change'],
            'items.*.contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^\\+?[0-9\\s-]{10,20}$/'],
        ]);

        $user = $request->user();
        abort_unless($user, 401);
        if (!$user->transaction_pin_hash) {
            return response()->json(['message' => 'Set up a transaction PIN before making wallet-funded purchases.', 'setup_url' => '/security/transaction-pin'], 428);
        }
        $pinStatus = app(TransactionPinService::class)->verify($user, (string) $validated['transaction_pin']);
        if ($pinStatus === 'locked') {
            return response()->json(['message' => 'Transaction PIN is locked for 15 minutes.'], 423);
        }
        if ($pinStatus !== 'verified') {
            return response()->json(['message' => 'Transaction PIN is incorrect.'], 422);
        }

        // Resolve and validate every item before accepting the batch. The persisted
        // records use internal product codes; API responses never return those codes.
        $prepared = [];
        $requestItems = [];
        $totalMinor = 0;
        foreach ($validated['items'] as $index => $item) {
            $product = $this->resolveCustomerSelectionCode($item['product_code']);
            if (!$product || !$product->is_active || !in_array($product->service_type, ['airtime', 'data'], true)
                || $product->currency !== 'NGN') {
                return response()->json(['message' => 'Item '.($index + 1).' is not an active airtime/data bulk selection. Other services remain unavailable for bulk purchase until their batch verification and recovery paths are enabled. No batch was accepted.'], 422);
            }
            if (!in_array($product->service_type, ['airtime', 'data'], true)
                && (($product->metadata['purchase_supported'] ?? false) !== true)) {
                return response()->json(['message' => 'Item '.($index + 1).' does not have an enabled purchase handler. No batch was accepted.'], 422);
            }

            $recipient = preg_replace('/[\\s-]+/', '', trim((string) $item['recipient'])) ?? '';
            $amountMinor = isset($item['amount']) ? (int) round((float) $item['amount'] * 100) : null;
            $details = array_filter([
                'meter_type' => $item['meter_type'] ?? null,
                'subscription_type' => $item['subscription_type'] ?? null,
                'contact_phone' => isset($item['contact_phone'])
                    ? (preg_replace('/[\\s-]+/', '', (string) $item['contact_phone']) ?? '')
                    : null,
            ], fn ($value) => $value !== null);

            if ($product->service_type === 'airtime' && ($amountMinor === null || $amountMinor < 100 || $amountMinor % 100 !== 0)) {
                return response()->json(['message' => 'Item '.($index + 1).' requires a whole-naira airtime amount. No batch was accepted.'], 422);
            }
            if ($product->service_type === 'electricity-bill') {
                if (!in_array($details['meter_type'] ?? null, ['prepaid', 'postpaid'], true) || $amountMinor === null
                    || !preg_match('/^\\+?[0-9]{10,15}$/', (string) ($details['contact_phone'] ?? ''))) {
                    return response()->json(['message' => 'Item '.($index + 1).' requires meter type, amount and a valid contact phone. No batch was accepted.'], 422);
                }
            }
            if ($product->service_type === 'tv-subscription'
                && (!preg_match('/^\\+?[0-9]{10,15}$/', (string) ($details['contact_phone'] ?? ''))
                    || ($details['subscription_type'] ?? 'change') !== 'change')) {
                return response()->json(['message' => 'Item '.($index + 1).' requires a valid contact phone and supported bouquet-change selection. No batch was accepted.'], 422);
            }

            $itemAmount = in_array($product->service_type, ['airtime', 'electricity-bill'], true)
                ? (int) $amountMinor
                : (int) $product->price_minor;
            if ($itemAmount < 1) {
                return response()->json(['message' => 'Item '.($index + 1).' has no valid server-calculated price. No batch was accepted.'], 422);
            }
            $totalMinor += $itemAmount;
            $prepared[] = [
                'item_index' => (int) $index,
                'product_code' => $product->code,
                'service_type' => $product->service_type,
                'recipient' => $recipient,
                'amount_minor' => $amountMinor,
                'service_details' => $details ?: null,
                'idempotency_key' => hash('sha256', $user->getKey().'|bulk:'.$validated['batch_key'].'|item:'.$index),
            ];
            $requestItems[] = [
                'product_code' => $product->code,
                'recipient' => $recipient,
                'amount_minor' => $amountMinor,
                'service_details' => $details,
            ];
        }

        $requestHash = hash('sha256', json_encode($requestItems, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $existing = VtuBulkPurchaseBatch::query()
            ->where('user_id', $user->getKey())
            ->where('batch_key', $validated['batch_key'])
            ->first();
        if ($existing) {
            if (!hash_equals($existing->request_hash, $requestHash)) {
                return response()->json(['message' => 'This batch key was already used for a different request. Use the original request to recover its status.'], 409);
            }
            return response()->json(['data' => $this->serializeBulkBatch($existing)], 200);
        }

        try {
            $batch = DB::transaction(function () use ($user, $validated, $requestHash, $prepared, $totalMinor): VtuBulkPurchaseBatch {
                $batch = VtuBulkPurchaseBatch::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->getKey(),
                    'batch_key' => $validated['batch_key'],
                    'request_hash' => $requestHash,
                    'status' => 'queued',
                    'item_count' => count($prepared),
                    'total_amount_minor' => $totalMinor,
                    'currency' => 'NGN',
                ]);
                foreach ($prepared as $item) {
                    $batch->items()->create($item + ['status' => 'pending']);
                }
                return $batch;
            });
        } catch (QueryException $exception) {
            // Only recover from the user+batch-key uniqueness race. Other SQL failures
            // must remain visible instead of being mistaken for a successful retry.
            if (! $this->isBatchKeyUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = VtuBulkPurchaseBatch::query()
                ->where('user_id', $user->getKey())
                ->where('batch_key', $validated['batch_key'])
                ->first();
            if (!$existing) throw $exception;
            if (!hash_equals($existing->request_hash, $requestHash)) {
                return response()->json(['message' => 'This batch key was already used for a different request.'], 409);
            }
            return response()->json(['data' => $this->serializeBulkBatch($existing)], 200);
        }

        ProcessVtuBulkPurchaseBatch::dispatch((int) $batch->getKey());

        return response()->json([
            'data' => $this->serializeBulkBatch($batch->fresh()),
            'message' => 'Batch accepted. Track each item using the batch status endpoint; do not submit a new batch while this one is processing.',
        ], 202);
    }

    public function recoverBulkBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_key' => ['required', 'string', 'min:8', 'max:128'],
        ]);
        $user = $request->user();
        abort_unless($user, 401);

        $batch = VtuBulkPurchaseBatch::query()
            ->where('user_id', $user->getKey())
            ->where('batch_key', $validated['batch_key'])
            ->first();

        if (!$batch) {
            return response()->json([
                'data' => null,
                'message' => 'No saved batch was found for this key yet. Keep the same key and original items when checking again.',
            ], 404);
        }

        return response()->json(['data' => $this->serializeBulkBatch($batch)]);
    }

    public function bulkBatchStatus(Request $request, string $publicId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);
        $batch = VtuBulkPurchaseBatch::query()
            ->where('user_id', $user->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();

        return response()->json(['data' => $this->serializeBulkBatch($batch)]);
    }

    /** @return array<string, mixed> */
    private function serializeBulkBatch(VtuBulkPurchaseBatch $batch): array
    {
        $batch->loadMissing('items');
        return [
            'id' => $batch->public_id,
            'status' => $batch->status,
            'item_count' => (int) $batch->item_count,
            'total_amount_minor' => (int) $batch->total_amount_minor,
            'currency' => $batch->currency,
            'started_at' => $batch->started_at?->toISOString(),
            'completed_at' => $batch->completed_at?->toISOString(),
            'items' => $batch->items->map(fn (VtuBulkPurchaseItem $item): array => [
                'item' => (int) $item->item_index,
                'service_type' => $item->service_type,
                'recipient' => $item->recipient,
                'status' => $item->status,
                'order_public_id' => $item->order_public_id,
                'message' => $item->result_message,
            ])->values()->all(),
        ];
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

    private function isBatchKeyUniqueConstraintViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? $exception->getPrevious()?->errorInfo ?? [];
        $message = strtolower($exception->getMessage().' '.($exception->getPrevious()?->getMessage() ?? ''));
        preg_match('/SQLSTATE\\[([0-9A-Z]{5})\\]/i', $message, $stateMatch);
        preg_match('/(?:error:|code )\\s*(\\d+)/i', $message, $driverMatch);
        $sqlState = strtoupper((string) ($errorInfo[0] ?? $stateMatch[1] ?? $exception->getCode()));
        $driverCode = (string) ($errorInfo[1] ?? $driverMatch[1] ?? '');

        $isUniqueViolation = $sqlState === '23505'
            || $driverCode === '1062'
            || ($sqlState === '23000' && str_contains($message, 'duplicate entry'))
            || ($driverCode === '19' && str_contains($message, 'unique constraint failed'))
            || ($sqlState === '23000' && str_contains($message, 'unique constraint failed'));

        return $isUniqueViolation
            && str_contains($message, 'vtu_bulk_purchase_batches')
            && str_contains($message, 'user_id')
            && str_contains($message, 'batch_key');
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
            'failure_code' => $order->failure_code,
            'created_at' => $order->created_at?->toISOString(),
            'completed_at' => $order->completed_at?->toISOString(),
        ];
    }
}
