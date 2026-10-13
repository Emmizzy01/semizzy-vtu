<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\Payments\PaymentFundingService;
use App\Services\Payments\PaymentIntentService;
use App\Services\Payments\WebhookSignatureVerifier;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PaymentFundingController extends Controller
{
    public function createIntent(
        Request $request,
        WalletAccountService $walletAccounts,
        PaymentIntentService $intents,
    ): JsonResponse {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:100', 'max:500000000'],
        ]);

        $user = $request->user();
        abort_unless($user, 401);

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', 'NGN')
            ->first();

        if ($wallet === null) {
            $wallet = $walletAccounts->openForUser($user, 'NGN');
        }

        try {
            $intent = $intents->create($wallet, (int) $validated['amount_minor']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount_minor' => $exception->getMessage()]);
        }

        return response()->json([
            'data' => [
                'id' => $intent->public_id,
                'reference' => $intent->reference,
                'amount_minor' => $intent->amount_minor,
                'currency' => $intent->currency,
                'status' => $intent->status,
                'expires_at' => $intent->expires_at?->toISOString(),
            ],
        ], 201);
    }

    public function webhook(
        Request $request,
        string $provider,
        WebhookSignatureVerifier $verifier,
        PaymentFundingService $funding,
    ): JsonResponse {
        abort_unless(preg_match('/^[a-z0-9_-]{2,40}$/', $provider) === 1, 404);

        try {
            $verifier->verify($request);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json(['message' => 'Webhook verification is not configured.'], 503);
        }

        $payload = $request->json()->all();
        $result = $funding->processVerifiedEvent($provider, $payload);

        return response()->json([
            'received' => true,
            'outcome' => $result['outcome'],
            'duplicate' => $result['duplicate'],
        ]);
    }
}