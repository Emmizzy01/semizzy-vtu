<?php

namespace App\\Services\\Vtu;

use App\\Models\\VtuOrder;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Log;
use Throwable;

class VtuOrderMonitoringService
{
    /**
     * Surface old ambiguous orders for human/provider escalation only.
     * Monitoring never refunds, retries, or changes an order's status.
     *
     * @return array{threshold_minutes:int,stuck_total:int,processing:int,awaiting_reconciliation:int,alerts_sent:int}
     */
    public function monitor(): array
    {
        $threshold = max(5, min(10080, (int) config('services.vtu.stuck_order_minutes', 60)));
        $cutoff = now()->subMinutes($threshold);

        $stale = VtuOrder::query()
            ->whereIn('status', ['processing', 'awaiting_reconciliation'])
            ->whereNotNull('dispatch_started_at')
            // Use created_at rather than updated_at: routine pending lookups update
            // updated_at and must not hide an order that has been unresolved for hours.
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit(500)
            ->get(['id', 'public_id', 'provider', 'status', 'failure_code', 'provider_reference', 'created_at', 'updated_at', 'dispatch_started_at']);

        $stats = [
            'threshold_minutes' => $threshold,
            'stuck_total' => $stale->count(),
            'processing' => $stale->where('status', 'processing')->count(),
            'awaiting_reconciliation' => $stale->where('status', 'awaiting_reconciliation')->count(),
            'alerts_sent' => 0,
        ];

        if ($stale->isEmpty()) {
            return $stats;
        }

        $records = $stale->map(fn (VtuOrder $order): array => [
            'order_id' => $order->public_id,
            'provider' => $order->provider,
            'status' => $order->status,
            'failure_code' => $order->failure_code,
            'provider_reference' => $order->provider_reference,
            'created_at' => $order->created_at?->toISOString(),
            'last_updated_at' => $order->updated_at?->toISOString(),
            'dispatch_started_at' => $order->dispatch_started_at?->toISOString(),
        ])->all();

        Log::critical('SEMIZZY VTU orders remain unresolved and require operator/provider review.', [
            'threshold_minutes' => $threshold,
            'count' => count($records),
            'orders' => $records,
            'automatic_retry' => false,
            'automatic_refund' => false,
        ]);

        $fingerprint = hash('sha256', implode('|', array_map(
            fn (array $record): string => $record['order_id'].':'.$record['status'],
            $records,
        )));
        if (! Cache::add('vtu:stale-order-alert:'.$fingerprint, true, now()->addMinutes(15))) {
            return $stats;
        }

        $webhook = config('services.payments.alert_webhook_url');
        if (is_string($webhook) && str_starts_with($webhook, 'https://')) {
            try {
                $response = Http::acceptJson()->asJson()->timeout(5)->post($webhook, [
                    'text' => 'SEMIZZY VTU alert: '.$stats['stuck_total'].' order(s) remain unresolved for at least '.$threshold.' minutes. Manual/provider review is required; no automatic retry or refund was performed.',
                    'source' => 'semizzy-vtu',
                    'summary' => $stats,
                    'orders' => $records,
                ]);
                if ($response->successful()) {
                    $stats['alerts_sent'] = 1;
                } else {
                    Log::warning('SEMIZZY VTU stale-order alert webhook returned a non-success response.', [
                        'http_status' => $response->status(),
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('SEMIZZY VTU stale-order alert webhook failed.', [
                    'exception' => $exception::class,
                ]);
            }
        }

        return $stats;
    }
}
