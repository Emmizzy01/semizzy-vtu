<?php

namespace Tests\Unit;

use App\Services\Addons\AddonLifecyclePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AddonLifecyclePolicyTest extends TestCase
{
    public function test_admission_requires_every_readiness_gate(): void
    {
        $this->assertSame(
            ['available' => true, 'reason' => 'ready'],
            (new AddonLifecyclePolicy())->assessAdmission('enabled', 'compatible', true, true, true),
        );
    }

    #[DataProvider('blockedAdmissionCases')]
    public function test_admission_fails_closed_when_a_gate_is_missing(
        string $addonStatus,
        string $compatibilityStatus,
        bool $implementationRegistered,
        bool $providerReady,
        bool $featureEnabled,
        string $expectedReason,
    ): void {
        $result = (new AddonLifecyclePolicy())->assessAdmission(
            $addonStatus,
            $compatibilityStatus,
            $implementationRegistered,
            $providerReady,
            $featureEnabled,
        );

        $this->assertFalse($result['available']);
        $this->assertSame($expectedReason, $result['reason']);
    }

    public static function blockedAdmissionCases(): array
    {
        return [
            'disabled addon' => ['disabled', 'compatible', true, true, true, 'addon_disabled'],
            'incompatible addon' => ['enabled', 'incompatible', true, true, true, 'addon_incompatible'],
            'unregistered implementation' => ['enabled', 'compatible', false, true, true, 'implementation_unregistered'],
            'feature flag off' => ['enabled', 'compatible', true, true, false, 'feature_disabled'],
            'provider unavailable' => ['enabled', 'compatible', true, false, true, 'provider_unavailable'],
            'unchecked compatibility' => ['enabled', 'unchecked', true, true, true, 'addon_incompatible'],
        ];
    }

    public function test_accepted_non_terminal_operation_can_recover_after_addon_disablement(): void
    {
        $this->assertTrue((new AddonLifecyclePolicy())->mayContinueAcceptedOperation(true, false, true));
    }

    public function test_recovery_rejects_new_terminal_or_unrecoverable_operations(): void
    {
        $policy = new AddonLifecyclePolicy();

        $this->assertFalse($policy->mayContinueAcceptedOperation(false, false, true));
        $this->assertFalse($policy->mayContinueAcceptedOperation(true, true, true));
        $this->assertFalse($policy->mayContinueAcceptedOperation(true, false, false));
    }
}
