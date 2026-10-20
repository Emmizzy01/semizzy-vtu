<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VtuProduct;
use App\Services\Security\TransactionPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransactionPinSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_funded_purchase_is_rejected_when_transaction_pin_is_not_configured(): void
    {
        $user = User::query()->create(['name' => 'PIN Required Customer', 'email' => 'pin-required@example.test', 'password' => 'not-a-real-password']);

        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => 'not-looked-up-before-pin',
            'recipient' => '08012345678',
            'idempotency_key' => 'pin-required-order',
        ])->assertStatus(428)
          ->assertJsonPath('setup_url', '/security/transaction-pin');

        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_wallet_funded_purchase_is_rejected_when_transaction_pin_is_missing_but_configured(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $user = User::query()->create(['name' => 'PIN Missing Customer', 'email' => 'pin-missing@example.test', 'password' => 'not-a-real-password']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('4928')])->save();

        $product = VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => 'pin-missing-valid-data',
            'name' => 'PIN Missing Data',
            'price_minor' => 1000,
            'face_value_minor' => 1000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['provider_service_id' => 'mtn'],
        ]);

        $wallet = app(\App\Services\Wallets\WalletAccountService::class)->openForUser($user, 'NGN');
        app(\App\Services\Wallets\WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'credit',
            amountMinor: 5000,
            idempotencyKey: 'pin-missing-opening-credit',
            reference: 'pin-missing-opening-credit',
            description: 'Test opening balance',
            actorUserId: (int) $user->getKey(),
        );

        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'idempotency_key' => 'pin-missing-order',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Enter your four-digit transaction PIN.');

        $this->assertSame(5000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_six_digit_legacy_pin_is_rejected_at_the_purchase_http_boundary(): void
    {
        $user = User::query()->create(['name' => 'Legacy PIN Purchase Customer', 'email' => 'legacy-pin-purchase@example.test', 'password' => 'not-a-real-password']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('492815')])->save();

        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => 'not-looked-up-before-pin',
            'recipient' => '08012345678',
            'idempotency_key' => 'legacy-six-digit-pin-purchase',
            'transaction_pin' => '492815',
        ])->assertStatus(422)
          ->assertJsonValidationErrors('transaction_pin');

        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_wallet_funded_purchase_is_rejected_for_an_incorrect_transaction_pin(): void
    {
        $user = User::query()->create(['name' => 'PIN Incorrect Customer', 'email' => 'pin-incorrect@example.test', 'password' => 'not-a-real-password']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('4928')])->save();

        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => 'not-looked-up-before-pin',
            'recipient' => '08012345678',
            'idempotency_key' => 'pin-incorrect-order',
            'transaction_pin' => '1111',
        ])->assertStatus(422)->assertJsonPath('message', 'Transaction PIN is incorrect.');

        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_pin_lockout_is_enforced_at_purchase_endpoint_even_with_correct_pin(): void
    {
        $user = User::query()->create(['name' => 'PIN Locked Customer', 'email' => 'pin-locked@example.test', 'password' => 'not-a-real-password']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('4928')])->save();

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->actingAs($user)->postJson('/wallet/vtu/orders', [
                'product_code' => 'not-looked-up-before-pin',
                'recipient' => '08012345678',
                'idempotency_key' => 'pin-lockout-'.$attempt,
                'transaction_pin' => '1111',
            ])->assertStatus(422);
        }

        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => 'not-looked-up-before-pin',
            'recipient' => '08012345678',
            'idempotency_key' => 'pin-lockout-fifth',
            'transaction_pin' => '1111',
        ])->assertStatus(423);

        // A correct PIN must not bypass the active lockout window.
        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => 'not-looked-up-before-pin',
            'recipient' => '08012345678',
            'idempotency_key' => 'pin-lockout-correct-pin',
            'transaction_pin' => '4928',
        ])->assertStatus(429);

        $this->assertSame(5, (int) $user->fresh()->transaction_pin_failed_attempts);
        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_locked_pin_blocks_a_valid_purchase_without_order_wallet_debit_or_provider_request(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $user = User::query()->create(['name' => 'Locked PIN Boundary Customer', 'email' => 'locked-pin-boundary@example.test', 'password' => 'not-a-real-password']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('4928')])->save();

        $product = VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => 'locked-pin-boundary-data',
            'name' => 'Test Data',
            'price_minor' => 1000,
            'face_value_minor' => 1000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['provider_service_id' => 'mtn'],
        ]);

        $wallet = app(\App\Services\Wallets\WalletAccountService::class)->openForUser($user, 'NGN');
        app(\App\Services\Wallets\WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'credit',
            amountMinor: 5000,
            idempotencyKey: 'locked-pin-boundary-opening-credit',
            reference: 'locked-pin-boundary-opening-credit',
            description: 'Test opening balance',
            actorUserId: (int) $user->getKey(),
        );

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user)->postJson('/wallet/vtu/orders', [
                'product_code' => $product->code,
                'recipient' => '08012345678',
                'idempotency_key' => 'locked-pin-boundary-failure-'.$attempt,
                'transaction_pin' => '1111',
            ])->assertStatus($attempt === 4 ? 423 : 422);
        }

        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'idempotency_key' => 'locked-pin-boundary-correct-pin',
            'transaction_pin' => '4928',
        ])->assertStatus(429);

        $this->assertSame(5000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_airtime_purchase_requires_customer_entered_amount_at_http_boundary(): void
    {
        $user = User::query()->create(['name' => 'Custom Airtime Customer', 'email' => 'custom-airtime@example.test', 'password' => 'not-a-real-password']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('4928')])->save();

        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'test_network',
            'code' => 'custom-airtime-'.uniqid(),
            'name' => 'Test Airtime',
            'price_minor' => 1000,
            'face_value_minor' => 1000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'idempotency_key' => 'airtime-amount-required',
            'transaction_pin' => '4928',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Enter the airtime amount you want to purchase.');

        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_incorrect_pin_cannot_submit_a_valid_airtime_purchase_or_debit_wallet(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $user = User::query()->create(['name' => 'PIN Boundary Customer', 'email' => 'pin-boundary@example.test', 'password' => 'not-a-real-password']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('4928')])->save();

        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'mtn',
            'code' => 'pin-boundary-airtime',
            'name' => 'PIN Boundary Airtime',
            'price_minor' => 1000,
            'face_value_minor' => 1000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['provider_service_id' => 'mtn'],
        ]);

        $wallet = app(\App\Services\Wallets\WalletAccountService::class)->openForUser($user, 'NGN');
        app(\App\Services\Wallets\WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'credit',
            amountMinor: 5000,
            idempotencyKey: 'pin-boundary-opening-credit',
            reference: 'pin-boundary-opening-credit',
            description: 'Test opening balance',
            actorUserId: (int) $user->getKey(),
        );

        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'idempotency_key' => 'pin-boundary-invalid-pin',
            'transaction_pin' => '1111',
            'amount' => 25,
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Transaction PIN is incorrect.');

        $this->assertSame(5000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_user_can_set_a_separate_hashed_transaction_pin(): void
    {
        $user = User::query()->create(['name' => 'PIN Customer', 'email' => 'pin-customer@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($user)->get('/security/transaction-pin')->assertOk();
        $this->actingAs($user)->put('/security/transaction-pin', ['pin' => '4928', 'pin_confirmation' => '4928'])->assertRedirect();
        $this->assertNotNull($user->fresh()->transaction_pin_hash);
        $this->assertTrue(Hash::check('4928', $user->fresh()->transaction_pin_hash));
        $this->assertDatabaseHas('transaction_security_events', ['user_id' => $user->id, 'event_type' => 'pin_created', 'outcome' => 'success']);
    }

    public function test_legacy_six_digit_pin_can_be_changed_to_the_new_four_digit_format(): void
    {
        $user = User::query()->create(['name' => 'Legacy PIN Customer', 'email' => 'legacy-pin@example.test', 'password' => 'not-a-real-password']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('492815')])->save();

        $this->actingAs($user)->put('/security/transaction-pin', [
            'current_pin' => '492815',
            'pin' => '7359',
            'pin_confirmation' => '7359',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('7359', $user->fresh()->transaction_pin_hash));
        $this->assertDatabaseHas('transaction_security_events', ['user_id' => $user->id, 'event_type' => 'pin_changed', 'outcome' => 'success']);
    }

    public function test_existing_pin_change_requires_the_correct_current_pin_and_preserves_the_hash_on_failure(): void
    {
        $user = User::query()->create([
            'name' => 'PIN Change Guard Customer',
            'email' => 'pin-change-guard@example.test',
            'password' => 'not-a-real-password',
        ]);
        $user->forceFill(['transaction_pin_hash' => Hash::make('4928')])->save();

        $this->actingAs($user)->put('/security/transaction-pin', [
            'current_pin' => '1111',
            'pin' => '7359',
            'pin_confirmation' => '7359',
        ])->assertSessionHasErrors('current_pin');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('4928', (string) $fresh->transaction_pin_hash));
        $this->assertFalse(Hash::check('7359', (string) $fresh->transaction_pin_hash));
        $this->assertSame(1, (int) $fresh->transaction_pin_failed_attempts);
        $this->assertDatabaseHas('transaction_security_events', [
            'user_id' => $user->id,
            'event_type' => 'pin_verification',
            'outcome' => 'failed',
        ]);
    }

    public function test_active_pin_lockout_prevents_pin_change_even_with_the_correct_current_pin(): void
    {
        $user = User::query()->create([
            'name' => 'PIN Change Lock Customer',
            'email' => 'pin-change-lock@example.test',
            'password' => 'not-a-real-password',
        ]);
        $user->forceFill([
            'transaction_pin_hash' => Hash::make('4928'),
            'transaction_pin_failed_attempts' => 5,
            'transaction_pin_locked_until' => now()->addMinutes(10),
        ])->save();

        $this->actingAs($user)->put('/security/transaction-pin', [
            'current_pin' => '4928',
            'pin' => '7359',
            'pin_confirmation' => '7359',
        ])->assertSessionHasErrors('current_pin');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('4928', (string) $fresh->transaction_pin_hash));
        $this->assertSame(5, (int) $fresh->transaction_pin_failed_attempts);
    }

    public function test_repeated_pin_and_simple_sequences_are_rejected(): void
    {
        $user = User::query()->create(['name' => 'PIN Customer', 'email' => 'pin-customer2@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($user)->put('/security/transaction-pin', ['pin' => '1111', 'pin_confirmation' => '1111'])->assertSessionHasErrors('pin');
        $this->actingAs($user)->put('/security/transaction-pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertSessionHasErrors('pin');
    }

    public function test_five_failed_verifications_lock_the_pin_for_fifteen_minutes(): void
    {
        $user = User::query()->create(['name' => 'PIN Customer', 'email' => 'pin-customer3@example.test', 'password' => 'not-a-real-password']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('4928')])->save();
        $pins = app(TransactionPinService::class);
        for ($i = 0; $i < 4; $i++) $this->assertSame('invalid', $pins->verify($user, '1111'));
        $this->assertSame('locked', $pins->verify($user, '1111'));
        $this->assertNotNull($user->fresh()->transaction_pin_locked_until);
        $this->assertDatabaseHas('transaction_security_events', ['user_id' => $user->id, 'event_type' => 'pin_verification', 'outcome' => 'locked']);
    }
}
