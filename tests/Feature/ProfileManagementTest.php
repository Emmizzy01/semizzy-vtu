<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_update_name_and_view_classification_without_changing_email(): void
    {
        $user = User::query()->create(['name' => 'Original Name', 'email' => 'profile@example.test', 'password' => 'initial-password']);
        $this->actingAs($user)->get('/profile')->assertOk()->assertInertia(fn ($page) => $page->component('Account/Profile')->where('profile.email', 'profile@example.test'));
        $this->actingAs($user)->put('/profile', ['name' => 'Updated Name', 'address' => '12 Example Street, Lagos', 'date_of_birth' => '1998-04-12', 'sex' => 'female', 'mobile_number' => '+2348012345678'])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Name', 'email' => 'profile@example.test', 'address' => '12 Example Street, Lagos', 'date_of_birth' => '1998-04-12', 'sex' => 'female', 'mobile_number' => '+2348012345678']);
        $this->assertDatabaseHas('transaction_security_events', ['user_id' => $user->id, 'event_type' => 'profile_name_updated', 'outcome' => 'success']);
    }

    public function test_profile_rejects_mobile_numbers_without_international_country_code(): void
    {
        $user = User::query()->create(['name' => 'Phone User', 'email' => 'phone-profile@example.test', 'password' => 'initial-password']);
        $this->actingAs($user)->put('/profile', ['name' => 'Phone User', 'mobile_number' => '08012345678'])->assertSessionHasErrors('mobile_number');
    }

    public function test_password_change_requires_current_password_and_strong_confirmed_new_password(): void
    {
        $user = User::query()->create(['name' => 'Password User', 'email' => 'password-profile@example.test', 'password' => 'Original-password-123']);
        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'wrong-password', 'password' => 'NewPassword456789', 'password_confirmation' => 'NewPassword456789',
        ])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'Original-password-123', 'password' => 'NewPassword456789', 'password_confirmation' => 'NewPassword456789',
        ])->assertRedirect();
        $this->assertTrue(Hash::check('NewPassword456789', $user->fresh()->password));
        $this->assertDatabaseHas('transaction_security_events', ['user_id' => $user->id, 'event_type' => 'password_changed', 'outcome' => 'success']);
    }
}
