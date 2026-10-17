<?php

namespace App\Http\Controllers\Vtu;

use App\Http\Controllers\Controller;
use App\Models\VtuProviderWebhookEvent;
use App\Services\Vtu\ProviderWebhookSignature;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VtuProviderWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ProviderWebhookSignature $signatures): JsonResponse
    {
        if (! in_array($provider, ['sogo', 'dataway'], true)) return response()->json(['error' => 'unsupported_provider'], 404);

        $rawBody = $request->getContent();
        if ($rawBody === '' || strlen($rawBody) > 65536) return response()->json(['error' => 'invalid_payload_size'], 413);

        $secret = (string) config("services.vtu.{$provider}.webhook_secret", '');
        $signature = $provider === 'sogo'
            ? (string) $request->header('X-Sogo-Signature-256', '')
            : (string) $request->header('X-Dataway-Signature', '');
        $valid = $provider === 'sogo'
            ? $signatures->verifySogo($rawBody, $signature, $secret)
            : $signatures->verifyDataway($rawBody, $signature, $secret);
        if (! $valid) return response()->json(['error' => 'invalid_signature'], 401);

        $payload = json_decode($rawBody, true);
        if (! is_array($payload) || ! is_array($payload['data'] ?? null) || ! is_string($payload['event'] ?? null)) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        $eventType = substr(trim($payload['event']), 0, 100);
        $reference = $payload['data']['reference'] ?? null;
        $reference = is_string($reference) && trim($reference) !== '' ? substr(trim($reference), 0, 191) : null;
        $eventId = $provider === 'sogo'
            ? ($payload['id'] ?? $request->header('X-Sogo-Delivery'))
            : hash('sha256', $rawBody);
        if (! is_string($eventId) || trim($eventId) === '' || strlen($eventId) > 191) {
            return response()->json(['error' => 'invalid_event_id'], 400);
        }

        $hash = hash('sha256', $rawBody);
        $attributes = [
            'provider' => $provider, 'event_id' => $eventId, 'event_type' => $eventType,
            'provider_reference' => $reference, 'payload' => $payload,
            'payload_sha256' => $hash, 'received_at' => now(),
        ];

        try {
            $event = VtuProviderWebhookEvent::query()->firstOrCreate(
                ['provider' => $provider, 'event_id' => $eventId],
                $attributes,
            );
        } catch (QueryException $exception) {
            $event = VtuProviderWebhookEvent::query()->where('provider', $provider)->where('event_id', $eventId)->first();
            if ($event === null) throw $exception;
        }

        if (! hash_equals((string) $event->payload_sha256, $hash) && ! $event->wasRecentlyCreated) {
            return response()->json(['error' => 'event_id_payload_conflict'], 409);
        }

        return response()->json(['accepted' => true, 'duplicate' => ! $event->wasRecentlyCreated], 202);
    }
}
