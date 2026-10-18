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
}
