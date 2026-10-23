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

    public function test_definitive_failure_signal_must_be_a_strict_boolean(): void
    {
        config([
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.multi_provider.definitive_failure_providers' => ['sogo'],
        ]);

        $policy = new ProviderFailoverPolicy();

        foreach (['true', 1, '1', null] as $signal) {
            $this->assertFalse(
                $policy->isDefinitiveFailure('sogo', ['status' => 'failed', 'definitive_failure' => $signal]),
                'Non-boolean definitive-failure signals must not authorize a refund or failover.'
            );
        }

        $this->assertTrue($policy->isDefinitiveFailure('sogo', [
            'status' => 'failed',
            'definitive_failure' => true,
        ]));
    }

    public function test_failover_requires_explicit_definitive_failure_and_provider_allowlist(): void
    {
        $policy = new ProviderFailoverPolicy();
        $result = ['status' => 'failed', 'definitive_failure' => true];

        config(['services.vtu.multi_provider.failover_enabled' => false]);
        config(['services.vtu.multi_provider.definitive_failure_providers' => ['sogo']]);
        $this->assertFalse($policy->mayFailover('sogo', $result), 'Failover remains disabled regardless of provider allowlist.');
        config(['services.vtu.multi_provider.failover_enabled' => true]);

        config(['services.vtu.multi_provider.definitive_failure_providers' => []]);
        $this->assertFalse($policy->mayFailover('sogo', $result));

        config(['services.vtu.multi_provider.definitive_failure_providers' => ['sogo']]);
        $this->assertTrue($policy->mayFailover('sogo', $result));
        $this->assertFalse($policy->mayFailover('dataway', $result));
    }
    public function test_definitive_failure_is_still_refundable_when_failover_is_disabled(): void
    {
        config([
            'services.vtu.multi_provider.failover_enabled' => false,
            'services.vtu.multi_provider.definitive_failure_providers' => ['sogo'],
        ]);
        $policy = new ProviderFailoverPolicy();
        $result = ['status' => 'failed', 'definitive_failure' => true];

        $this->assertTrue($policy->isDefinitiveFailure('sogo', $result));
        $this->assertFalse($policy->mayFailover('sogo', $result));
    }

}
