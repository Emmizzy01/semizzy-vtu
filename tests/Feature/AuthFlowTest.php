<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_authentication_pages_render_without_server_errors(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/Login'));
        $this->get('/register')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/Register'));
        $this->get('/forgot-password')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_authenticated_users_are_redirected_away_from_login_and_registration(): void
    {
        $user = User::query()->create([
            'name' => 'Existing Customer',
            'email' => 'existing@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->actingAs($user)->get('/login')->assertRedirect('/dashboard');
        $this->actingAs($user)->get('/register')->assertRedirect('/dashboard');
    }

    public function test_invalid_login_returns_a_field_error_without_authenticating(): void
    {
        $this->from('/login')->post('/login', [
            'identifier' => 'missing@example.test',
            'password' => 'WrongPassword1234',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_login_requires_a_valid_email_and_password(): void
    {
        $this->from('/login')->post('/login', [
            'identifier' => '',
            'password' => '',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['identifier', 'password']);

        $this->assertGuest();
    }

    public function test_user_can_sign_in_with_username(): void
    {
        $user = User::query()->create([
            'name' => 'Username Customer',
            'email' => 'username-customer@example.test',
            'username' => 'semizzycustomer',
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', [
            'identifier' => 'SemizzyCustomer',
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_sign_in_with_a_verified_whatsapp_number_only(): void
    {
        $user = User::query()->create([
            'name' => 'WhatsApp Customer',
            'email' => 'whatsapp-customer@example.test',
            'whatsapp_number' => '+2348012345678',
            'whatsapp_verified_at' => now(),
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', [
            'identifier' => '+234 801 234 5678',
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_unverified_whatsapp_number_cannot_be_used_to_sign_in(): void
    {
        User::query()->create([
            'name' => 'Unverified WhatsApp Customer',
            'email' => 'unverified-whatsapp@example.test',
            'whatsapp_number' => '+2348098765432',
            'password' => 'CorrectPassword1234',
        ]);

        $this->from('/login')->post('/login', [
            'identifier' => '+2348098765432',
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login')->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_user_can_sign_in_with_email_address(): void
    {
        $user = User::query()->create([
            'name' => 'Email Customer',
            'email' => 'email-customer@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', [
            'identifier' => ' EMAIL-CUSTOMER@EXAMPLE.TEST ',
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_normalizes_email_and_authenticates_the_new_customer(): void
    {
        $this->post('/register', [
            'name' => 'New Customer',
            'email' => '  NEW-CUSTOMER@EXAMPLE.TEST  ',
            'password' => 'ValidPassword1234',
            'password_confirmation' => 'ValidPassword1234',
        ])->assertRedirect('/dashboard');

        $this->assertDatabaseHas('users', [
            'email' => 'new-customer@example.test',
            'name' => 'New Customer',
        ]);
        $this->assertAuthenticated();
    }

    public function test_registration_can_create_a_hashed_transaction_pin_and_audit_event(): void
    {
        $this->post('/register', [
            'name' => 'PIN Registration Customer',
            'email' => 'pin-registration@example.test',
            'password' => 'ValidPassword1234',
            'password_confirmation' => 'ValidPassword1234',
            'transaction_pin' => '4928',
            'transaction_pin_confirmation' => '4928',
        ])->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'pin-registration@example.test')->firstOrFail();

        $this->assertNotNull($user->transaction_pin_hash);
        $this->assertTrue(Hash::check('4928', $user->transaction_pin_hash));
        $this->assertNotSame('4928', $user->transaction_pin_hash);
        $this->assertDatabaseHas('transaction_security_events', [
            'user_id' => $user->id,
            'event_type' => 'pin_created',
            'outcome' => 'success',
        ]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_weak_or_unconfirmed_transaction_pin(): void
    {
        $this->from('/register')->post('/register', [
            'name' => 'Weak PIN Customer',
            'email' => 'weak-pin-registration@example.test',
            'password' => 'ValidPassword1234',
            'password_confirmation' => 'ValidPassword1234',
            'transaction_pin' => '1234',
            'transaction_pin_confirmation' => '1234',
        ])->assertRedirect('/register')->assertSessionHasErrors('transaction_pin');

        $this->from('/register')->post('/register', [
            'name' => 'Mismatch PIN Customer',
            'email' => 'mismatch-pin-registration@example.test',
            'password' => 'ValidPassword1234',
            'password_confirmation' => 'ValidPassword1234',
            'transaction_pin' => '4928',
            'transaction_pin_confirmation' => '4929',
        ])->assertRedirect('/register')->assertSessionHasErrors('transaction_pin_confirmation');

        $this->assertDatabaseMissing('users', ['email' => 'weak-pin-registration@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'mismatch-pin-registration@example.test']);
        $this->assertGuest();
    }

    public function test_registration_rejects_duplicate_email_addresses(): void
    {
        User::query()->create([
            'name' => 'Existing Customer',
            'email' => 'taken@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->from('/register')->post('/register', [
            'name' => 'Another Customer',
            'email' => 'TAKEN@example.test',
            'password' => 'ValidPassword1234',
            'password_confirmation' => 'ValidPassword1234',
        ])
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_rejects_weak_or_unconfirmed_passwords(): void
    {
        $this->from('/register')->post('/register', [
            'name' => 'New Customer',
            'email' => 'new-customer@example.test',
            'password' => 'weak',
            'password_confirmation' => 'different',
        ])
            ->assertRedirect('/register')
            ->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'email' => 'new-customer@example.test',
        ]);
    }
}
