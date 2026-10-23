<?php

namespace App\Services\Vtu;

use App\Models\VtuOrder;
use App\Models\VtuProviderWebhookEvent;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

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
            // Multiple scheduler/queue workers can select the same eligible row.
            // Claim it atomically so only one worker processes this delivery in the backoff window.
            $claimed = DB::transaction(function () use ($event): bool {
                $locked = VtuProviderWebhookEvent::query()->whereKey($event->getKey())->lockForUpdate()->first();
                if ($locked === null || $locked->processed_at !== null
                    || ($locked->last_attempted_at !== null && $locked->last_attempted_at->greaterThan(now()->subMinutes(5)))) {
                    return false;
                }

                $locked->forceFill(['last_attempted_at' => now()])->save();
                return true;
            }, attempts: 3);
            if (! $claimed) {
                continue;
            }
            $event->refresh();
            $stats['checked']++;
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
            } catch (Throwable $exception) {
                // Leave the event unprocessed for retry after backoff, and keep one
                // transient application/provider failure from aborting the whole batch.
                report($exception);
                $event->forceFill(['processing_error' => 'Webhook processing failed unexpectedly; retry scheduled after backoff.'])->save();
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
            // The signed event name and embedded status must agree. Never let a
            // contradictory payload turn a failure into a success (or vice versa).
            return match ([$eventType, $status]) {
                ['transaction.completed', 'completed'],
                ['transaction.completed', 'success'],
                ['transaction.completed', 'successful'] => 'success',
                ['transaction.failed', 'failed'] => 'failed',
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
