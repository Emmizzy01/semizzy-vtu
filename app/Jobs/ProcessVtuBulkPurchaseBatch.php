<?php

namespace App\Jobs;

use App\Exceptions\Wallet\InsufficientWalletFunds;
use App\Models\VtuBulkPurchaseBatch;
use App\Models\VtuBulkPurchaseItem;
use App\Services\Vtu\VtuOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class ProcessVtuBulkPurchaseBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $batchId)
    {
        $this->afterCommit();
    }

    public function handle(VtuOrderService $orders): void
    {
        $batch = VtuBulkPurchaseBatch::query()->find($this->batchId);
        if (!$batch || in_array($batch->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }

        VtuBulkPurchaseBatch::query()->whereKey($batch->id)->update([
            'status' => 'processing',
            'started_at' => $batch->started_at ?? now(),
            'last_error' => null,
        ]);

        // Processing items are included intentionally: the order service's stable
        // idempotency key returns the existing order after a worker interruption.
        $items = VtuBulkPurchaseItem::query()
            ->where('batch_id', $batch->id)
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('item_index')
            ->get();

        foreach ($items as $item) {
            $claimed = DB::transaction(function () use ($item): bool {
                $locked = VtuBulkPurchaseItem::query()->whereKey($item->id)->lockForUpdate()->first();
                if (!$locked || !in_array($locked->status, ['pending', 'processing'], true)) {
                    return false;
                }
                $locked->update(['status' => 'processing', 'started_at' => $locked->started_at ?? now()]);
                return true;
            });
            if (!$claimed) continue;

            try {
                $order = $orders->purchase(
                    $batch->user,
                    $item->product_code,
                    $item->recipient,
                    $item->idempotency_key,
                    $item->service_details ?? [],
                    $item->amount_minor,
                );
                $orderStatus = (string) $order->status;
                $final = in_array($orderStatus, ['successful', 'failed', 'cancelled'], true);
                $item->update([
                    'status' => $final ? $orderStatus : 'unknown',
                    'order_public_id' => $order->public_id,
                    'result_message' => $final ? null : 'Provider order status is '.$orderStatus.'. Reconcile the existing order before any retry.',
                    'completed_at' => now(),
                ]);
            } catch (InvalidArgumentException|InsufficientWalletFunds|ConflictHttpException $exception) {
                $item->update([
                    'status' => 'rejected',
                    'result_message' => mb_substr($exception->getMessage(), 0, 1000),
                    'completed_at' => now(),
                ]);
            } catch (LogicException $exception) {
                report($exception);
                $item->update([
                    'status' => 'unavailable',
                    'result_message' => 'The service is not configured. No further attempt was made.',
                    'completed_at' => now(),
                ]);
            } catch (Throwable $exception) {
                // Never automatically resubmit an item after an unclassified error:
                // the provider may have received it. Reconcile the stable order key.
                report($exception);
                $item->update([
                    'status' => 'unknown',
                    'result_message' => 'Outcome is uncertain. Reconcile the existing idempotency reference before any retry.',
                    'completed_at' => now(),
                ]);
            }

            $this->refreshBatchStatus($batch->id);
        }

        $this->refreshBatchStatus($batch->id);
    }

    public function failed(?Throwable $exception): void
    {
        $batch = VtuBulkPurchaseBatch::query()->find($this->batchId);
        if (!$batch) return;

        // A processing item could have reached a provider before the worker died.
        // Preserve it as unknown rather than queueing another submission.
        VtuBulkPurchaseItem::query()->where('batch_id', $batch->id)
            ->where('status', 'processing')
            ->update([
                'status' => 'unknown',
                'result_message' => 'Worker stopped during this item. Reconcile its stable order reference before retrying.',
                'completed_at' => now(),
            ]);

        $this->refreshBatchStatus($batch->id);
    }

    private function refreshBatchStatus(int $batchId): void
    {
        $batch = VtuBulkPurchaseBatch::query()->find($batchId);
        if (!$batch) return;

        $statuses = VtuBulkPurchaseItem::query()->where('batch_id', $batchId)
            ->pluck('status');
        $unfinished = $statuses->contains(fn (string $status): bool => in_array($status, ['pending', 'processing'], true));
        $hasSuccess = $statuses->contains(fn (string $status): bool => $status === 'successful');
        $hasIssue = $statuses->contains(fn (string $status): bool => in_array($status, ['failed', 'cancelled', 'rejected', 'unavailable'], true));
        $hasUnknown = $statuses->contains(fn (string $status): bool => $status === 'unknown');
        $status = $unfinished ? 'processing' : (($hasUnknown || ($hasSuccess && $hasIssue)) ? 'partial' : ($hasSuccess ? 'completed' : 'failed'));

        $batch->update([
            'status' => $status,
            'completed_at' => $unfinished ? null : now(),
        ]);
    }
}
