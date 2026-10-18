<?php

namespace Tests\Feature;

use App\Exceptions\Wallet\IdempotencyConflict;
use App\Models\EducationOrder;
use App\Models\EducationProviderAttempt;
use App\Models\EducationServiceProduct;
use App\Models\PlatformFeatureControl;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletTransaction;
use App\Services\Education\EducationOrderCoordinator;
use App\Services\Education\EducationProvider;
use App\Services\Education\EducationProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EducationOrderCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private function setupApprovedProduct(string $code = 'waec-coordinator-test'): array
    {
        $user = User::query()->create([
            'name' => 'Education Coordinator Buyer',
            'email' => 'education-coordinator@example.test',
            'password' => 'not-a-real-password',
        ]);
        PlatformFeatureControl::query()->where('feature_key', 'service.education')->update([
            'is_enabled' => true, 'is_implemented' => true,
        ]);
        $product = EducationServiceProduct::query()->create([
            'service_type' => 'exam_pin', 'exam_body' => 'WAEC',
            'name' => 'Coordinator WAEC PIN', 'code' => $code,
            'provider' => 'test-education', 'provider_product_code' => 'waec-test',
            'price_minor' => 150000, 'currency' => 'NGN',
            'requires_candidate_validation' => true, 'is_active' => true, 'purchase_approved' => true,
        ]);
        $wallet = Wallet::query()->create([
            'user_id' => $user->id, 'currency' => 'NGN', 'status' => 'active',
            'balance_minor' => 500000, 'available_balance_minor' => 500000, 'version' => 0,
        ]);

        return [$user, $product, $wallet];
    }

    private function bindProvider(string $status): object
    {
        $provider = new class($status) implements EducationProvider {
            public int $submissions = 0;
            public function __construct(private string $status) {}
            public function assertConfigured(): void {}
            public function validateCandidate(EducationServiceProduct $product, array $candidate): array
            {
                return ['status' => 'valid', 'reference' => 'candidate-ok'];
            }
            public function submit(string $orderReference, EducationServiceProduct $product, array $candidate): array
            {
                $this->submissions++;
                return ['status' => $this->status, 'provider_reference' => 'provider-ref-1', 'message_code' => $this->status === 'failed' ? 'definitive_failure' : null];
            }
            public function lookup(string $orderReference, EducationServiceProduct $product): array
            {
                return ['status' => 'unknown'];
            }
        };
        app()->instance('education.providers.test-education', $provider);
        return $provider;
    }

    public function test_success_debits_once_and_duplicate_request_never_resubmits(): void
    {
        [$user, $product, $wallet] = $this->setupApprovedProduct();
        $provider = $this->bindProvider('success');
        $coordinator = app(EducationOrderCoordinator::class);
        $candidate = ['candidate_number' => '1234567890'];

        $order = $coordinator->purchase($user, $product->code, 'education-idem-success', $candidate);
        $duplicate = $coordinator->purchase($user, $product->code, 'education-idem-success', $candidate);

        $this->assertSame('successful', $order->status);
        $this->assertSame($order->id, $duplicate->id);
        $this->assertSame(1, $provider->submissions);
        $this->assertSame(350000, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('education_orders', 1);
        $this->assertDatabaseCount('education_provider_attempts', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

    public function test_definitive_failure_refunds_exactly_once(): void
    {
        [$user, $product, $wallet] = $this->setupApprovedProduct('waec-coordinator-fail');
        $provider = $this->bindProvider('failed');
        $order = app(EducationOrderCoordinator::class)->purchase(
            $user, $product->code, 'education-idem-failure', ['candidate_number' => '1234567890']
        );

        $this->assertSame('failed', $order->status);
        $this->assertSame(500000, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
        $this->assertSame(1, $provider->submissions);
    }

    public function test_unknown_provider_result_keeps_debit_and_duplicate_does_not_retry(): void
    {
        [$user, $product, $wallet] = $this->setupApprovedProduct('waec-coordinator-unknown');
        $provider = $this->bindProvider('unknown');
        $coordinator = app(EducationOrderCoordinator::class);
        $candidate = ['candidate_number' => '1234567890'];

        $order = $coordinator->purchase($user, $product->code, 'education-idem-unknown', $candidate);
        $coordinator->purchase($user, $product->code, 'education-idem-unknown', $candidate);

        $this->assertSame('unknown', $order->status);
        $this->assertSame(350000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(1, $provider->submissions);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

    public function test_uncertain_provider_lookup_keeps_order_unknown_and_never_refunds_or_resubmits(): void
    {
        [$user, $product, $wallet] = $this->setupApprovedProduct('waec-coordinator-uncertain-lookup');
        $provider = new class implements EducationProvider {
            public int $submissions = 0;
            public int $lookups = 0;
            public function assertConfigured(): void {}
            public function validateCandidate(EducationServiceProduct $product, array $candidate): array
            {
                return ['status' => 'valid'];
            }
            public function submit(string $orderReference, EducationServiceProduct $product, array $candidate): array
            {
                $this->submissions++;
                return ['status' => 'unknown', 'provider_reference' => 'uncertain-provider-ref'];
            }
            public function lookup(string $orderReference, EducationServiceProduct $product): array
            {
                $this->lookups++;
                return ['status' => 'unknown', 'message_code' => 'LOOKUP_NOT_CONCLUSIVE'];
            }
        };
        app()->instance('education.providers.test-education', $provider);

        $coordinator = app(EducationOrderCoordinator::class);
        $order = $coordinator->purchase(
            $user, $product->code, 'education-idem-uncertain-lookup', ['candidate_number' => '1234567890']
        );
        $this->assertSame('unknown', $order->status);
        $this->assertSame(1, $provider->submissions);

        $reconciled = $coordinator->reconcile($order->fresh());
        $this->assertSame('unknown', $reconciled->status);
        $this->assertSame('LOOKUP_NOT_CONCLUSIVE', $reconciled->failure_code ?? $reconciled->safe_provider_response['message_code'] ?? null);
        $this->assertSame(1, $provider->lookups);
        $this->assertSame(1, $provider->submissions, 'An uncertain lookup must not cause a purchase resubmission.');
        $this->assertSame(350000, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 1, 'An uncertain lookup must not issue a refund.');
        $this->assertSame(0, WalletTransaction::query()->where('type', 'credit')->count());
    }

    public function test_idempotency_key_cannot_be_reused_for_different_candidate_payload(): void
    {
        [$user, $product] = $this->setupApprovedProduct('waec-coordinator-conflict');
        $this->bindProvider('success');
        $coordinator = app(EducationOrderCoordinator::class);
        $coordinator->purchase($user, $product->code, 'education-idem-conflict', ['candidate_number' => '1234567890']);

        $this->expectException(IdempotencyConflict::class);
        $coordinator->purchase($user, $product->code, 'education-idem-conflict', ['candidate_number' => '9999999999']);
    }

    public function test_reconciliation_uses_persisted_provider_and_never_resubmits(): void
    {
        [$user, $product, $wallet] = $this->setupApprovedProduct('waec-coordinator-reconcile');
        $provider = new class implements EducationProvider {
            public int $submissions = 0;
            public int $lookups = 0;
            public function assertConfigured(): void {}
            public function validateCandidate(EducationServiceProduct $product, array $candidate): array
            {
                return ['status' => 'valid'];
            }
            public function submit(string $orderReference, EducationServiceProduct $product, array $candidate): array
            {
                $this->submissions++;
                return ['status' => 'unknown'];
            }
            public function lookup(string $orderReference, EducationServiceProduct $product): array
            {
                $this->lookups++;
                return ['status' => 'success', 'provider_reference' => 'reconciled-ref'];
            }
        };
        app()->instance('education.providers.test-education', $provider);

        $coordinator = app(EducationOrderCoordinator::class);
        $order = $coordinator->purchase(
            $user, $product->code, 'education-idem-reconcile', ['candidate_number' => '1234567890']
        );
        $this->assertSame('unknown', $order->status);
        $this->assertSame(1, $provider->submissions);

        // Simulate catalogue remapping after dispatch: reconciliation must still use
        // the provider identity stored on the order, never the product's new mapping.
        $product->forceFill(['provider' => 'different-provider'])->save();
        $reconciled = $coordinator->reconcile($order->fresh());

        $this->assertSame('successful', $reconciled->status);
        $this->assertSame('test-education', $reconciled->provider);
        $this->assertSame(1, $provider->submissions);
        $this->assertSame(1, $provider->lookups);
        $this->assertSame(350000, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

}
