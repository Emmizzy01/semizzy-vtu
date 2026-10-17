<?php

namespace App\Services\Vtu;

use App\Models\VtuOrder;
use App\Models\VtuProviderWebhookEvent;
use LogicException;

class VtuProviderWebhookService
{
    public function __construct(private readonly VtuOrderService $orders)
    {
    }

    /** @return array{checked:int,processed:int,unmatched:int,ignored:int} */
    public function process(int $limit = 100): array
    {
        $stats = ['checked' => 0, 'processed' => 0, 'unmatched' => 0, 'ignored' => 0];
        $events = VtuProviderWebhookEvent::query()
            ->whereNull('processed_at')
            ->where(function ($query): void {
                $query->whereNull('last_attempted_at')->orWhere('last_attempted_at', '<=', now()->subMinutes(5));
            })
            ->orderBy('id')->limit($limit)->get();

        foreach ($events as $event) {
            $stats['checked']++;
            $event->forceFill(['last_attempted_at' => now()])->save();
            $status = $this->normalizedStatus($event->provider, $event->event_type, $event->payload);

            if ($status === null) {
                $event->forceFill(['processed_at' => now(), 'processing_error' => 'Ignored unsupported webhook event type or status.'])->save();
                $stats['ignored']++;
                continue;
            }

            if (! is_string($event->provider_reference) || $event->provider_reference === '') {
                $event->forceFill(['processed_at' => now(), 'processing_error' => 'Webhook event has no provider reference.'])->save();
                $stats['ignored']++;
                continue;
            }

            $order = VtuOrder::query()->where('provider', $event->provider)
                ->where('provider_reference', $event->provider_reference)->first();
            if ($order === null) {
                $event->forceFill(['processing_error' => 'Waiting for the purchase response to persist its provider reference.'])->save();
                $stats['unmatched']++;
                continue;
            }

            try {
                $this->orders->applyVerifiedWebhookResult($order, $event->provider, $event->provider_reference, [
                    'status' => $status,
                    'provider_reference' => $event->provider_reference,
                    'message_code' => 'webhook:'.$event->event_type,
                ]);
            } catch (LogicException $exception) {
                report($exception);
                $event->forceFill(['processing_error' => 'Verified event did not match recorded order owner/reference.'])->save();
                continue;
            }

            $event->forceFill(['processed_at' => now(), 'processing_error' => null])->save();
            $stats['processed']++;
        }

        return $stats;
    }

    /** @param array<string, mixed>|null $payload */
    private function normalizedStatus(string $provider, string $eventType, ?array $payload): ?string
    {
        $status = strtolower((string) data_get($payload, 'data.status', ''));
        if ($provider === 'sogo') {
            return match ($eventType) {
                'transaction.completed' => 'success',
                'transaction.failed', 'transaction.refunded', 'transaction.cancelled' => 'failed',
                default => null,
            };
        }

        if ($provider === 'dataway') {
            return match ([$eventType, $status]) {
                ['transaction.successful', 'successful'], ['transaction.successful', 'success'] => 'success',
                ['transaction.pending', 'pending'], ['transaction.pending', 'processing'] => 'pending',
                ['transaction.failed', 'failed'] => 'failed',
                default => null,
            };
        }

        return null;
    }
}
