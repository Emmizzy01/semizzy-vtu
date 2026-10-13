<?php

namespace App\Services\Payments;

use App\Models\PaymentRefund;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentRefundMonitoringService
{
    /**
     * Alert on refunds whose lifecycle has stopped progressing.
     *
     * @return array{threshold_minutes:int,stuck_total:int,processing:int,provider_pending:int,review_required:int,alerts_sent:int}
     */
    public function monitor(): array
    {
        $threshold = max(5, min(1440, (int) config('services.payments.refund_stale_minutes', 15)));
        $cutoff = now()->subMinutes($threshold);
        $stale = PaymentRefund::query()
            ->whereIn('status', ['processing', 'provider_pending', 'review_required'])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(500)
            ->get(['id', 'public_id', 'payment_intent_id', 'amount_minor', 'status', 'provider_status', 'provider_refund_reference', 'updated_at']);

        $stats = [
            'threshold_minutes' => $threshold,
            'stuck_total' => $stale->count(),
            'processing' => $stale->where('status', 'processing')->count(),
            'provider_pending' => $stale->where('status', 'provider_pending')->count(),
            'review_required' => $stale->where('status', 'review_required')->count(),
            'alerts_sent' => 0,
        ];

        if ($stale->isEmpty()) {
            return $stats;
        }

        $records = $stale->map(fn (PaymentRefund $refund): array => [
            'refund_id' => $refund->public_id,
            'status' => $refund->status,
            'amount_minor' => $refund->amount_minor,
            'payment_intent_id' => $refund->payment_intent_id,
            'provider_status' => $refund->provider_status,
            'provider_refund_reference' => $refund->provider_refund_reference,
            'last_updated_at' => $refund->updated_at?->toISOString(),
        ])->all();

        Log::critical('SEMIZZY refund lifecycle has stale items requiring reconciliation.', [
            'threshold_minutes' => $threshold,
            'count' => count($records),
            'refunds' => $records,
        ]);

        // Throttle duplicate notifications, but always keep the event in application logs.
        $fingerprint = hash('sha256', implode('|', array_map(
            fn (array $record): string => $record['refund_id'].':'.$record['status'],
            $records,
        )));
        if (! Cache::add('payments:refund-stale-alert:'.$fingerprint, true, now()->addMinutes(15))) {
            return $stats;
        }

        $webhook = config('services.payments.alert_webhook_url');
        if (is_string($webhook) && str_starts_with($webhook, 'https://')) {
            try {
                $response = Http::acceptJson()->asJson()->timeout(5)->post($webhook, [
                    'text' => 'SEMIZZY refund alert: '.$stats['stuck_total'].' refund(s) have not progressed for at least '.$threshold.' minutes.',
                    'source' => 'semizzy-payments',
                    'summary' => $stats,
                    'refunds' => $records,
                ]);
                if ($response->successful()) {
                    $stats['alerts_sent'] = 1;
                } else {
                    Log::warning('SEMIZZY refund alert webhook returned a non-success response.', [
                        'http_status' => $response->status(),
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('SEMIZZY refund alert webhook failed.', [
                    'exception' => $exception::class,
                ]);
            }
        }

        return $stats;
    }
}
