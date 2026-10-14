<?php

namespace Tests\\Feature;

use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

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
            'email' => 'missing@example.test',
            'password' => 'WrongPassword1234',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_requires_a_valid_email_and_password(): void
    {
        $this->from('/login')->post('/login', [
            'email' => 'not-an-email',
            'password' => '',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
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
