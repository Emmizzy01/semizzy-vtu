<?php

namespace App\Http\Controllers\Vtu;

use App\Http\Controllers\Controller;
use App\Models\VtuProduct;
use App\Services\Vtu\VtpassProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

class VtuBillerVerificationController extends Controller
{
    public function verify(Request $request, VtpassProviderClient $provider): JsonResponse
    {
        $validated = $request->validate([
            'product_code' => ['required', 'string', 'max:80'],
            'biller_code' => ['required', 'string', 'min:5', 'max:40', 'regex:/^[a-zA-Z0-9-]+$/'],
            'meter_type' => ['nullable', 'string', 'in:prepaid,postpaid'],
        ]);

        $product = $this->resolveCustomerSelectionCode($validated['product_code']);

        if ($product !== null && (! in_array($product->service_type, ['electricity-bill', 'tv-subscription'], true)
            || $product->currency !== 'NGN')) {
            $product = null;
        }

        if (! $product) {
            return response()->json(['message' => 'Choose a valid electricity or TV service.'], 422);
        }

        if ($product->service_type === 'electricity-bill' && ! isset($validated['meter_type'])) {
            return response()->json(['message' => 'Select prepaid or postpaid for this electricity meter.'], 422);
        }

        try {
            $result = $product->service_type === 'electricity-bill'
                ? $provider->verifyElectricityMeter($product, $validated['biller_code'], $validated['meter_type'])
                : $provider->verifyTvSmartcard($product, $validated['biller_code']);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json([
                'data' => [
                    'status' => 'unknown',
                    'message_code' => null,
                    'customer_name' => null,
                    'customer_address' => null,
                ],
                'message' => 'The provider verification result is unavailable. Do not submit a purchase until you can verify the account.',
            ], 503);
        } catch (LogicException $exception) {
            report($exception);

            return response()->json(['message' => 'This provider verification service is not configured.'], 503);
        }

        return response()->json([
            'data' => $result,
            'message' => match ($result['status']) {
                'verified' => 'Account details verified. Check the displayed customer before proceeding.',
                'unverified' => 'The provider could not verify these account details.',
                default => 'Verification is inconclusive. Do not proceed with a purchase yet.',
            },
        ], $result['status'] === 'unknown' ? 503 : 200);
    }
    /** Resolve a signed public selection key; legacy internal codes remain accepted for compatibility. */
    private function resolveCustomerSelectionCode(string $code): ?VtuProduct
    {
        if (preg_match('/^p([0-9a-z]+)\\.([a-f0-9]{32})$/D', $code, $matches) === 1) {
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

}
