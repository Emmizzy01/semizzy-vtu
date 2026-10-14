<?php

namespace Tests\\Feature;

use App\\Models\\StaffPermissionAudit;
use App\\Models\\User;
use App\\Models\\UserPermission;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class FirstAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email): User
    {
        return User::query()->create([
            'name' => 'Bootstrap Target',
            'email' => $email,
            'password' => 'CorrectPassword1234',
        ]);
    }

    public function test_bootstrap_requires_explicit_force_confirmation(): void
    {
        $user = $this->account('admin@example.test');

        $this->artisan('semizzy:bootstrap-admin', ['email' => $user->email])
            ->assertExitCode(1);

        $this->assertDatabaseCount('user_permissions', 0);
        $this->assertDatabaseCount('staff_permission_audits', 0);
    }

    public function test_bootstrap_grants_first_admin_permission_and_records_audit(): void
    {
        $user = $this->account('admin@example.test');

        $this->artisan('semizzy:bootstrap-admin', [
            'email' => $user->email,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $user->id,
            'permission' => 'staff.permissions.manage',
            'granted_by_user_id' => null,
        ]);

        $this->assertDatabaseHas('staff_permission_audits', [
            'actor_user_id' => null,
            'target_user_id' => $user->id,
            'permission' => 'staff.permissions.manage',
            'action' => 'granted',
            'reason' => 'One-time first administrator bootstrap via server CLI; operator-confirmed target email.',
        ]);

        $this->assertSame(1, UserPermission::query()->count());
        $this->assertSame(1, StaffPermissionAudit::query()->count());
    }

    public function test_bootstrap_refuses_to_run_if_privileged_access_already_exists(): void
    {
        $first = $this->account('first-admin@example.test');
        $second = $this->account('second-admin@example.test');

        UserPermission::query()->create([
            'user_id' => $first->id,
            'permission' => 'staff.permissions.manage',
        ]);

        $this->artisan('semizzy:bootstrap-admin', [
            'email' => $second->email,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $second->id,
            'permission' => 'staff.permissions.manage',
        ]);
        $this->assertDatabaseCount('staff_permission_audits', 0);
    }

    public function test_bootstrap_refuses_unknown_email_without_changes(): void
    {
        $this->artisan('semizzy:bootstrap-admin', [
            'email' => 'missing@example.test',
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('user_permissions', 0);
        $this->assertDatabaseCount('staff_permission_audits', 0);
    }
}
