<?php

namespace Tests\Unit;

use App\Services\Vtu\ProviderFailoverPolicy;
use Tests\TestCase;

class ProviderFailoverPolicyTest extends TestCase
{
    public function test_unknown_timeout_pending_and_generic_failed_results_never_fail_over(): void
    {
        config(['services.vtu.multi_provider.definitive_failure_providers' => ['sogo', 'gsubz']]);
        $policy = new ProviderFailoverPolicy();

        foreach ([
            ['status' => 'unknown'],
            ['status' => 'pending'],
            ['status' => 'failed'],
            ['status' => 'failed', 'definitive_failure' => false],
            ['status' => 'success', 'definitive_failure' => true],
        ] as $result) {
            $this->assertFalse($policy->mayFailover('sogo', $result));
        }
    }

    public function test_failover_requires_explicit_definitive_failure_and_provider_allowlist(): void
    {
        $policy = new ProviderFailoverPolicy();
        $result = ['status' => 'failed', 'definitive_failure' => true];

        config(['services.vtu.multi_provider.definitive_failure_providers' => []]);
        $this->assertFalse($policy->mayFailover('sogo', $result));

        config(['services.vtu.multi_provider.definitive_failure_providers' => ['sogo']]);
        $this->assertTrue($policy->mayFailover('sogo', $result));
        $this->assertFalse($policy->mayFailover('dataway', $result));
    }
}
