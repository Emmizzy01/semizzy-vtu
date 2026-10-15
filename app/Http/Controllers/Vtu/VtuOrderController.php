<?php

namespace App\Http\Controllers\Vtu;

use App\Http\Controllers\Controller;
use App\Exceptions\Wallet\InsufficientWalletFunds;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\Vtu\VtuOrderService;
use App\Services\IntegrationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VtuOrderController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        if (! filter_var(app(IntegrationSettings::class)->get('service.airtime_data.enabled', true), FILTER_VALIDATE_BOOLEAN)) return response()->json(['message' => 'Airtime and data purchases are temporarily unavailable.'], 503);
        $validated = $request->validate([
            'service_type' => ['sometimes', 'string', 'in:airtime,data'],
            'network' => ['sometimes', 'string', 'max:40'],
        ]);

        $products = VtuProduct::query()
            ->where('is_active', true)
            ->where('currency', 'NGN')
            ->when(isset($validated['service_type']), fn ($query) => $query->where('service_type', $validated['service_type']))
            ->when(isset($validated['network']), fn ($query) => $query->where('network', $validated['network']))
            ->orderBy('service_type')
            ->orderBy('network')
            ->orderBy('price_minor')
            ->get(['code', 'service_type', 'network', 'name', 'price_minor', 'face_value_minor', 'currency']);

        return response()->json(['data' => $products]);
    }

    public function purchase(Request $request, VtuOrderService $orders): JsonResponse
    {
        if (! filter_var(app(IntegrationSettings::class)->get('service.airtime_data.enabled', true), FILTER_VALIDATE_BOOLEAN)) return response()->json(['message' => 'Airtime and data purchases are temporarily unavailable.'], 503);
        $validated = $request->validate([
            'product_code' => ['required', 'string', 'max:80'],
            'recipient' => ['required', 'string', 'max:32'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);

        $user = $request->user();
        abort_unless($user, 401);

        try {
            $order = $orders->purchase(
                $user,
                $validated['product_code'],
                $validated['recipient'],
                $validated['idempotency_key'],
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
