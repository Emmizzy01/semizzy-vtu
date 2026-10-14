<?php

namespace App\\Http\\Controllers\\Payments;

use App\\Http\\Controllers\\Controller;
use App\\Models\\PaymentIntent;
use App\\Models\\Wallet;
use App\\Services\\Payments\\PaymentFundingService;
use App\\Services\\Payments\\PaymentProviderExceptionService;
use App\\Services\\Payments\\PaymentRefundService;
use App\\Services\\Payments\\PaymentIntentService;
use App\\Services\\Payments\\PaystackClient;
use App\\Services\\Payments\\WebhookSignatureVerifier;
use App\\Services\\Wallets\\WalletAccountService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Validation\\ValidationException;
use InvalidArgumentException;
use LogicException;
use Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException;
use Symfony\\Component\\HttpKernel\\Exception\\UnprocessableEntityHttpException;

class PaymentFundingController extends Controller
{
    public function createIntent(
        Request $request,
        WalletAccountService $walletAccounts,
        PaymentIntentService $intents,
        PaystackClient $paystack,
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
            $intent = $intents->create($wallet, (int) $validated['amount_minor'], 'paystack');
            $checkout = $paystack->initialize($intent, (string) $user->email);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount_minor' => $exception->getMessage()]);
        } catch (LogicException $exception) {
            report($exception);

            return response()->json(['message' => 'Payment checkout could not be initialized. Please try again.'], 502);
        }

        $intent->forceFill([
            'checkout_url' => $checkout['authorization_url'],
            'metadata' => [
                'paystack_access_code' => $checkout['access_code'] ?? null,
            ],
        ])->save();

        return response()->json([
            'data' => [
                'id' => $intent->public_id,
                'reference' => $intent->reference,
                'amount_minor' => $intent->amount_minor,
                'currency' => $intent->currency,
                'status' => $intent->status,
                'checkout_url' => $intent->checkout_url,
                'expires_at' => $intent->expires_at?->toISOString(),
            ],
        ], 201);
    }

    public function webhook(
        Request $request,
        string $provider,
        WebhookSignatureVerifier $verifier,
        PaystackClient $paystack,
        PaymentFundingService $funding,
        PaymentProviderExceptionService $providerExceptions,
        PaymentRefundService $refunds,
    ): JsonResponse {
        abort_unless(in_array($provider, ['paystack', 'semizzy'], true), 404);

        if ($provider === 'paystack') {
            try {
                if (! $paystack->verifyWebhookSignature(
                    $request->getContent(),
                    (string) $request->header('x-paystack-signature', ''),
                )) {
                    abort(401, 'Paystack webhook signature verification failed.');
                }
            } catch (LogicException $exception) {
                report($exception);

                return response()->json(['message' => 'Paystack verification is not configured.'], 503);
            }

            $payload = $request->json()->all();
            $eventType = $payload['event'] ?? null;
            if (! is_string($eventType) || ! is_array($payload['data'] ?? null)) {
                return response()->json(['message' => 'Paystack event schema is invalid.'], 400);
            }

            if (str_starts_with($eventType, 'refund.') || str_starts_with($eventType, 'charge.dispute.')) {
                $refundMatched = $refunds->applyPaystackEvent($eventType, $payload);
                $result = $providerExceptions->recordPaystackEvent($eventType, $payload);

                return response()->json([
                    'received' => true,
                    'outcome' => $result['outcome'],
                    'case_key' => $result['case_key'],
                    'refund_matched' => $refundMatched,
                ]);
            }

            if ($eventType !== 'charge.success') {
                return response()->json(['received' => true, 'outcome' => 'ignored', 'duplicate' => false]);
            }

            $webhookData = $payload['data'];
            $reference = $webhookData['reference'] ?? null;
            if (! is_string($reference) || $reference === '') {
                throw new UnprocessableEntityHttpException('Paystack event has no payment reference.');
            }

            try {
                $verified = $paystack->verifyTransaction($reference);
            } catch (LogicException $exception) {
                report($exception);

                return response()->json(['message' => 'Paystack transaction could not be verified.'], 503);
            }

            if (($verified['status'] ?? null) !== 'success'
                || ($verified['reference'] ?? null) !== $reference
                || ($verified['currency'] ?? null) !== 'NGN'
                || ! is_int($verified['amount'] ?? null)
                || ! is_numeric($verified['id'] ?? null)
                || (string) ($webhookData['id'] ?? '') !== (string) $verified['id']
                || (int) ($webhookData['amount'] ?? -1) !== $verified['amount']
                || ($webhookData['currency'] ?? null) !== $verified['currency']) {
                throw new ConflictHttpException('Paystack webhook and verified transaction do not match.');
            }

            $normalized = [
                'id' => 'paystack-charge-success-'.$verified['id'],
                'type' => 'payment.succeeded',
                'data' => [
                    'reference' => $verified['reference'],
                    'transaction_id' => (string) $verified['id'],
                    'status' => 'succeeded',
                    'amount_minor' => $verified['amount'],
                    'currency' => $verified['currency'],
                ],
            ];

            $result = $funding->processVerifiedEvent('paystack', $normalized);
        } else {
            try {
                $verifier->verify($request, $provider);
            } catch (LogicException $exception) {
                report($exception);

                return response()->json(['message' => 'Webhook verification is not configured.'], 503);
            }

            $payload = $request->json()->all();
            $result = $funding->processVerifiedEvent($provider, $payload);
        }

        return response()->json([
            'received' => true,
            'outcome' => $result['outcome'],
            'duplicate' => $result['duplicate'],
        ]);
    }

    public function returnFromCheckout(
        Request $request,
        PaystackClient $paystack,
        PaymentFundingService $funding,
    ): RedirectResponse {
        // Trust the provider's server-to-server verification response, never browser query parameters alone.
        $reference = $request->query('reference');
        if (! is_string($reference) || $reference === '') {
            return redirect()->route('dashboard', ['funding' => 'unverified']);
        }

        try {
            $verified = $paystack->verifyTransaction($reference);
        } catch (LogicException $exception) {
            report($exception);

            return redirect()->route('dashboard', ['funding' => 'pending']);
        }

        $intent = PaymentIntent::query()->where('reference', $reference)->first();
        if ($intent === null || $intent->provider !== 'paystack'
            || ($verified['status'] ?? null) !== 'success'
            || ($verified['reference'] ?? null) !== $intent->reference
            || ! is_numeric($verified['id'] ?? null)
            || (int) ($verified['amount'] ?? -1) !== $intent->amount_minor
            || ($verified['currency'] ?? null) !== $intent->currency) {
            return redirect()->route('dashboard', ['funding' => 'pending']);
        }

        // A successful server-side verification can settle the intent safely.
        // The same transaction ID is idempotent if the signed webhook arrives later.
        $funding->processVerifiedEvent('paystack', [
            'id' => 'paystack-return-'.(string) $verified['id'],
            'type' => 'payment.succeeded',
            'data' => [
                'reference' => $intent->reference,
                'transaction_id' => (string) $verified['id'],
                'status' => 'succeeded',
                'amount_minor' => (int) $verified['amount'],
                'currency' => (string) $verified['currency'],
            ],
        ]);

        $settled = $intent->fresh()->status === 'paid';

        return redirect()->route('dashboard', [
            'funding' => $settled ? 'success' : 'pending',
        ]);
    }
}
