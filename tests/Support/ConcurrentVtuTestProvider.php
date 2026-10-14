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

        return [
            'status' => 'pending',
            'provider_reference' => 'concurrency-provider-'.$order->public_id,
        ];
    }

    public function lookup(VtuOrder $order): array
    {
        $this->increment('lookups');
        $this->arriveAndWait('lookup_barrier', 2);

        return [
            'status' => 'failed',
            'message_code' => 'CONCURRENCY_TEST_FAILURE',
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
