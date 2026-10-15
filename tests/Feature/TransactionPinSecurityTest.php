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

    public function test_user_can_set_a_separate_hashed_transaction_pin(): void
    {
        $user = User::query()->create(['name' => 'PIN Customer', 'email' => 'pin-customer@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($user)->get('/security/transaction-pin')->assertOk();
        $this->actingAs($user)->put('/security/transaction-pin', ['pin' => '492815', 'pin_confirmation' => '492815'])->assertRedirect();
        $this->assertNotNull($user->fresh()->transaction_pin_hash);
        $this->assertTrue(Hash::check('492815', $user->fresh()->transaction_pin_hash));
        $this->assertDatabaseHas('transaction_security_events', ['user_id' => $user->id, 'event_type' => 'pin_created', 'outcome' => 'success']);
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
        $user->forceFill(['transaction_pin_hash' => Hash::make('492815')])->save();
        $pins = app(TransactionPinService::class);
        for ($i = 0; $i < 4; $i++) $this->assertSame('invalid', $pins->verify($user, '111111'));
        $this->assertSame('locked', $pins->verify($user, '111111'));
        $this->assertNotNull($user->fresh()->transaction_pin_locked_until);
        $this->assertDatabaseHas('transaction_security_events', ['user_id' => $user->id, 'event_type' => 'pin_verification', 'outcome' => 'locked']);
    }
}
