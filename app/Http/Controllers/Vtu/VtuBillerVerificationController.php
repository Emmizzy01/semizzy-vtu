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

        $product = VtuProduct::query()
            ->where('code', $validated['product_code'])
            ->whereIn('service_type', ['electricity-bill', 'tv-subscription'])
            ->where('currency', 'NGN')
            ->first();

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
}
