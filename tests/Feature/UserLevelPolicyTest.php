<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserLevelPolicy;
use App\Models\UserPermission;
use App\Models\VtuProduct;
use App\Services\Vtu\VtuOrderService;
use App\Services\Vtu\VtuProvider;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UserLevelPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_configure_and_audit_four_level_policy(): void
    {
        $admin = User::query()->create(['name' => 'Policy Admin', 'email' => 'policy-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $this->actingAs($admin)->get('/admin/user-levels')->assertOk();
        $this->actingAs($admin)->put('/admin/user-levels/level_1', [
            'max_single_transaction_minor' => 100000,
            'daily_transaction_limit_minor' => 300000,
            'requires_verified_kyc' => true,
            'is_active' => true,
            'reason' => 'Apply verified customer transaction policy',
        ])->assertRedirect();
        $this->assertDatabaseHas('user_level_policies', ['level' => 'level_1', 'max_single_transaction_minor' => 100000, 'daily_transaction_limit_minor' => 300000, 'requires_verified_kyc' => 1]);
        $this->assertDatabaseHas('user_level_policy_audits', ['level' => 'level_1', 'actor_user_id' => $admin->id]);
    }

    public function test_single_transaction_limit_is_enforced_before_any_wallet_debit(): void
    {
        config(['services.vtu.name' => 'test_provider', 'services.vtu.endpoint' => 'https://provider.example.test/api', 'services.vtu.token' => 'test-secret']);
        $provider = new class implements VtuProvider {
            public function assertConfigured(): void {}
            public function assertProductConfigured(VtuProduct $product): void {}
            public function submit(\App\Models\VtuOrder $order): array { return ['status' => 'pending']; }
            public function lookup(\App\Models\VtuOrder $order): array { return ['status' => 'pending']; }
        };
        $this->app->instance(VtuProvider::class, $provider);
        $user = User::query()->create(['name' => 'Limited Customer', 'email' => 'limited-customer@example.test', 'password' => 'not-a-real-password']);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post($wallet->id, 'credit', 5000, 'policy-test-funding');
        $product = VtuProduct::query()->create(['service_type' => 'airtime', 'network' => 'mtn', 'code' => 'policy-test-airtime', 'name' => 'Policy Test Airtime', 'price_minor' => 1000, 'face_value_minor' => 1000, 'currency' => 'NGN', 'is_active' => true]);
        UserLevelPolicy::query()->where('level', 'level_1')->update(['max_single_transaction_minor' => 500]);

        try {
            app(VtuOrderService::class)->purchase($user, $product->code, '08012345678', 'policy-test-order');
            $this->fail('A purchase above the configured level cap must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('single-transaction limit', $exception->getMessage());
            $this->assertSame(5000, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
        }
    }
}
