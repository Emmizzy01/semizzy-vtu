<?php

namespace Tests\Feature;

use App\Models\User;
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

    public function test_user_can_set_a_separate_hashed_transaction_pin(): void
    {
        $user = User::query()->create(['name' => 'PIN Customer', 'email' => 'pin-customer@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($user)->get('/security/transaction-pin')->assertOk();
        $this->actingAs($user)->put('/security/transaction-pin', ['pin' => '492815', 'pin_confirmation' => '492815'])->assertRedirect();
        $this->assertNotNull($user->fresh()->transaction_pin_hash);
        $this->assertTrue(Hash::check('492815', $user->fresh()->transaction_pin_hash));
        $this->assertDatabaseHas('transaction_security_events', ['user_id' => $user->id, 'event_type' => 'pin_created', 'outcome' => 'success']);
    }

    public function test_legacy_four_digit_pin_can_be_changed_to_the_new_six_digit_format(): void
    {
        $user = User::query()->create(['name' => 'Legacy PIN Customer', 'email' => 'legacy-pin@example.test', 'password' => 'not-a-real-password']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('4928')])->save();

        $this->actingAs($user)->put('/security/transaction-pin', [
            'current_pin' => '4928',
            'pin' => '735915',
            'pin_confirmation' => '735915',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('735915', $user->fresh()->transaction_pin_hash));
        $this->assertDatabaseHas('transaction_security_events', ['user_id' => $user->id, 'event_type' => 'pin_changed', 'outcome' => 'success']);
    }

    public function test_repeated_pin_and_simple_sequences_are_rejected(): void
    {
        $user = User::query()->create(['name' => 'PIN Customer', 'email' => 'pin-customer2@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($user)->put('/security/transaction-pin', ['pin' => '111111', 'pin_confirmation' => '111111'])->assertSessionHasErrors('pin');
        $this->actingAs($user)->put('/security/transaction-pin', ['pin' => '123456', 'pin_confirmation' => '123456'])->assertSessionHasErrors('pin');
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
