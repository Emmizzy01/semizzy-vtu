<?php

namespace Tests\Support;

use App\Models\EducationServiceProduct;
use App\Services\Education\EducationProvider;
use RuntimeException;

/** Process-safe fake for the dedicated MariaDB education concurrency suite only. */
final class ConcurrentEducationTestProvider implements EducationProvider
{
    public function __construct(private readonly string $statePath)
    {
    }

    public function assertConfigured(): void
    {
    }

    public function validateCandidate(EducationServiceProduct $product, array $candidate): array
    {
        return ['status' => 'valid', 'reference' => 'education-candidate-test-valid'];
    }

    public function submit(string $orderReference, EducationServiceProduct $product, array $candidate): array
    {
        // The reservation transaction has committed before this boundary.
        if ($this->readInt('crash_before_submit') === 1) {
            exit(87);
        }

        $this->increment('submissions');

        // Model provider acceptance followed by a lost application response.
        if ($this->readInt('crash_after_acceptance') === 1) {
            exit(86);
        }

        return [
            'status' => 'unknown',
            'provider_reference' => 'education-provider-'.$orderReference,
        ];
    }

    public function lookup(string $orderReference, EducationServiceProduct $product): array
    {
        $lookupNumber = $this->increment('lookups');
        if ($this->readInt('lookup_barrier') === 1) {
            $this->arriveAndWait('lookup_arrivals', 2);
        }

        $status = (string) $this->readValue('lookup_status', 'unknown');
        if ($status === 'mixed') {
            $status = $lookupNumber === 1 ? 'failed' : 'success';
        }

        return [
            'status' => $status,
            'provider_reference' => 'education-provider-'.$orderReference,
            'message_code' => $status === 'failed' ? 'EDUCATION_TEST_DEFINITIVE_FAILURE' : null,
        ];
    }

    public function arriveAndWait(string $barrier, int $participants): void
    {
        $this->increment($barrier);
        $deadline = microtime(true) + 20;

        do {
            if ($this->readInt($barrier) >= $participants) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Timed out waiting for education concurrency barrier: '.$barrier);
    }

    private function increment(string $key): int
    {
        $handle = fopen($this->statePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open education concurrency state file.');
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
            return (int) $state[$key];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readInt(string $key): int
    {
        return (int) $this->readValue($key, 0);
    }

    private function readValue(string $key, mixed $default): mixed
    {
        $handle = fopen($this->statePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open education concurrency state file.');
        }

        try {
            flock($handle, LOCK_SH);
            rewind($handle);
            $state = json_decode(stream_get_contents($handle) ?: '{}', true);
            return is_array($state) ? ($state[$key] ?? $default) : $default;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
