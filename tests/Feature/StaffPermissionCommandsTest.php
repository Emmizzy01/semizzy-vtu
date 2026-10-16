<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPermissionCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_grant_requires_explicit_force_flag(): void
    {
        $user = User::query()->create([
            'name' => 'Finance Operator',
            'email' => 'finance-operator@example.test',
            'password' => 'test-password',
        ]);

        $this->artisan('semizzy:permission:grant', [
            'email' => $user->email,
            'permission' => 'refunds.approve',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $user->id,
            'permission' => 'refunds.approve',
        ]);
    }

    public function test_grant_is_allowlisted_and_idempotent_and_revoke_removes_access(): void
    {
        $user = User::query()->create([
            'name' => 'Finance Operator',
            'email' => 'finance-operator@example.test',
            'password' => 'test-password',
        ]);

        $this->artisan('semizzy:permission:grant', [
            'email' => $user->email,
            'permission' => 'users.manage',
            '--force' => true,
        ])->assertExitCode(1);

        $this->artisan('semizzy:permission:grant', [
            'email' => strtoupper($user->email),
            'permission' => 'refunds.approve',
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('semizzy:permission:grant', [
            'email' => $user->email,
            'permission' => 'refunds.approve',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, UserPermission::query()
            ->where('user_id', $user->id)
            ->where('permission', 'refunds.approve')
            ->count());

        $this->artisan('semizzy:permission:revoke', [
            'email' => $user->email,
            'permission' => 'refunds.approve',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $user->id,
            'permission' => 'refunds.approve',
        ]);
    }

    public function test_permission_commands_reject_unknown_accounts(): void
    {
        $this->artisan('semizzy:permission:grant', [
            'email' => 'missing@example.test',
            'permission' => 'refunds.approve',
            '--force' => true,
        ])->assertExitCode(1);
    }
}
