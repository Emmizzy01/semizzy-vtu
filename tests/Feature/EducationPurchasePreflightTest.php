<?php

namespace Tests\Feature;

use App\Models\EducationServiceProduct;
use App\Models\PlatformFeatureControl;
use App\Models\User;
use App\Services\Education\EducationPurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EducationPurchasePreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_rejects_disabled_feature_before_provider_or_wallet_activity(): void
    {
        $user = User::query()->create([
            'name' => 'Education Buyer',
            'email' => 'education-buyer@example.test',
            'password' => 'not-a-real-password',
        ]);

        EducationServiceProduct::query()->create([
            'service_type' => 'exam_pin', 'exam_body' => 'WAEC',
            'name' => 'Locked WAEC PIN', 'code' => 'locked-waec-pin',
            'provider' => 'unregistered-provider', 'price_minor' => 150000,
            'is_active' => true, 'purchase_approved' => true,
        ]);

        try {
            app(EducationPurchaseService::class)->preflight($user, 'locked-waec-pin', 'edu-idem-001', ['candidate_number' => '1234567890']);
            $this->fail('Expected disabled education feature to reject preflight.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }

        $this->assertDatabaseCount('education_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_preflight_rejects_unregistered_provider_without_creating_financial_records(): void
    {
        $user = User::query()->create([
            'name' => 'Education Buyer Two',
            'email' => 'education-buyer-two@example.test',
            'password' => 'not-a-real-password',
        ]);
        PlatformFeatureControl::query()->where('feature_key', 'service.education')->update([
            'is_enabled' => true, 'is_implemented' => true,
        ]);
        EducationServiceProduct::query()->create([
            'service_type' => 'exam_pin', 'exam_body' => 'WAEC',
            'name' => 'Unverified WAEC PIN', 'code' => 'unverified-waec-pin',
            'provider' => 'unregistered-provider', 'price_minor' => 150000,
            'is_active' => true, 'purchase_approved' => true,
        ]);

        try {
            app(EducationPurchaseService::class)->preflight($user, 'unverified-waec-pin', 'edu-idem-002', ['candidate_number' => '1234567890']);
            $this->fail('Expected unregistered provider to reject preflight.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('not verified or registered', $exception->getMessage());
        }

        $this->assertDatabaseCount('education_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_provider_configuration_or_authorization_failure_blocks_purchase_before_financial_records(): void
    {
        $user = User::query()->create([
            'name' => 'Education Authorization Gate Buyer',
            'email' => 'education-authorization-gate@example.test',
            'password' => 'not-a-real-password',
        ]);
        PlatformFeatureControl::query()->where('feature_key', 'service.education')->update([
            'is_enabled' => true, 'is_implemented' => true,
        ]);
        EducationServiceProduct::query()->create([
            'service_type' => 'exam_pin', 'exam_body' => 'WAEC',
            'name' => 'Provider Authorization Gate PIN', 'code' => 'authorization-gate-waec-pin',
            'provider' => 'authorization-gate-provider', 'provider_product_code' => 'waec-test',
            'price_minor' => 150000, 'currency' => 'NGN',
            'requires_candidate_validation' => true, 'is_active' => true, 'purchase_approved' => true,
        ]);

        app()->instance('education.providers.authorization-gate-provider', new class implements \App\Services\Education\EducationProvider {
            public function assertConfigured(): void
            {
                throw new LogicException('Provider merchant authorization has not been verified.');
            }
            public function validateCandidate(EducationServiceProduct $product, array $candidate): array
            {
                return ['status' => 'valid'];
            }
            public function submit(string $orderReference, EducationServiceProduct $product, array $candidate): array
            {
                throw new LogicException('Submission must not run when authorization is unverified.');
            }
            public function lookup(string $orderReference, EducationServiceProduct $product): array
            {
                return ['status' => 'unknown'];
            }
        });

        try {
            app(EducationPurchaseService::class)->preflight(
                $user, 'authorization-gate-waec-pin', 'edu-idem-auth-gate', ['candidate_number' => '1234567890']
            );
            $this->fail('Expected unverified provider authorization to block preflight.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('authorization has not been verified', $exception->getMessage());
        }

        $this->assertDatabaseCount('education_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
    }
}
