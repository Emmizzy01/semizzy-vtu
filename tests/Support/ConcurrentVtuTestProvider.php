<?php

namespace Tests\Support;

use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\Vtu\VtuProvider;
use RuntimeException;

final class ConcurrentVtuTestProvider implements VtuProvider
{
    public function __construct(private readonly string $statePath)
    {
    }

    public function assertConfigured(): void
    {
    }

    public function assertProductConfigured(VtuProduct $product): void
    {
    }

    public function submit(VtuOrder $order): array
    {
        $this->increment('submissions');

        // Simulate the remote provider accepting a request immediately before
        // the application worker is forcibly terminated, leaving no saved result.
        if ($this->read('crash_after_submit') === 1) {
            exit(86);
        }

        return [
            'status' => 'pending',
            'provider_reference' => 'concurrency-provider-'.$order->public_id,
        ];
    }

    public function lookup(VtuOrder $order): array
    {
        $lookupNumber = $this->increment('lookups');
        if ($this->read('single_lookup') !== 1) {
            $this->arriveAndWait('lookup_barrier', 2);
        }

        // Crash recovery models a provider that accepted the original submit;
        // reconciliation discovers that accepted purchase without resubmitting.
        // Mixed-result mode exercises contradictory concurrent terminal outcomes.
        $status = $this->read('crash_after_submit') === 1
            ? 'success'
            : ($this->read('mixed_outcomes') === 1
                ? ($lookupNumber === 1 ? 'success' : 'failed')
                : 'failed');

        return [
            'status' => $status,
            'message_code' => $status === 'failed' ? 'CONCURRENCY_TEST_FAILURE' : null,
            'provider_reference' => 'concurrency-provider-'.$order->public_id,
        ];
    }

    public function arriveAndWait(string $barrier, int $participants): void
    {
        $this->increment($barrier);

        $deadline = microtime(true) + 15;
        do {
            if ($this->read($barrier) >= $participants) {
                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Timed out waiting for concurrency barrier: '.$barrier);
    }

    public function increment(string $key): int
    {
        $handle = fopen($this->statePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open concurrency state file.');
        }

        try {
            flock($handle, LOCK_EX);
            rewind($handle);
            $state = json_decode(stream_get_contents($handle) ?: '{}', true);
            $state = is_array($state) ? $state : [];
            $state[$key] = ((int) ($state[$key] ?? 0)) + 1;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $state[$key];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function read(string $key): int
    {
        $handle = fopen($this->statePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open concurrency state file.');
        }

        try {
            flock($handle, LOCK_SH);
            rewind($handle);
            $state = json_decode(stream_get_contents($handle) ?: '{}', true);

            return is_array($state) ? (int) ($state[$key] ?? 0) : 0;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
