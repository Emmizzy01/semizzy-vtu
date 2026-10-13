<?php

namespace App\Services\Payments;

use App\Models\PaymentRefund;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentRefundReconciliationService
{
    public function __construct(private readonly PaymentRefundService $refunds)
    {
    }

    /**
     * Refresh old refund states using Paystack's canonical refund endpoint.
     *
     * @return array{checked:int,reconciled:int,mismatched:int,unavailable:int,skipped:int}
     */
    public function reconcile(int $limit = 100): array
    {
        $threshold = max(5, min(1440, (int) config('services.payments.refund_stale_minutes', 15)));
        $stats = ['checked' => 0, 'reconciled' => 0, 'mismatched' => 0, 'unavailable' => 0, 'skipped' => 0];

        PaymentRefund::query()
            ->whereIn('status', ['processing', 'provider_pending', 'review_required'])
            ->where('updated_at', '<=', now()->subMinutes($threshold))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->each(function (PaymentRefund $refund) use (&$stats): void {
                $stats['checked']++;

                try {
                    $result = $this->refunds->reconcileProviderStatus($refund->public_id);
                    $key = match ($result['outcome']) {
                        'reconciled' => 'reconciled',
                        'mismatch' => 'mismatched',
                        'unavailable' => 'unavailable',
                        default => 'skipped',
                    };
                    $stats[$key]++;
                } catch (Throwable $exception) {
                    $stats['unavailable']++;
                    Log::warning('Refund reconciliation failed unexpectedly.', [
                        'refund_id' => $refund->public_id,
                        'exception' => $exception::class,
                    ]);
                }
            });

        return $stats;
    }
}
