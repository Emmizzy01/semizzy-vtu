<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_ticket_and_only_read_own_ticket(): void
    {
        $customer = User::query()->create(['name' => 'Support Customer', 'email' => 'support-customer@example.test', 'password' => 'not-a-real-password']);
        $other = User::query()->create(['name' => 'Other Customer', 'email' => 'support-other@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($customer)->post('/support', ['category' => 'wallet', 'subject' => 'Wallet funding question', 'body' => 'Please help me understand this funding status.'])->assertRedirect();
        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame($customer->id, $ticket->user_id);
        $this->actingAs($customer)->get('/support?ticket='.$ticket->id)->assertOk()->assertSee($ticket->public_id);
        $this->actingAs($other)->get('/support?ticket='.$ticket->id)->assertOk()->assertDontSee($ticket->public_id);
        $this->assertDatabaseHas('support_ticket_audits', ['ticket_id' => $ticket->id, 'action' => 'created']);
    }

    public function test_admin_can_reply_and_status_changes_are_audited(): void
    {
        $admin = User::query()->create(['name' => 'Support Admin', 'email' => 'support-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $customer = User::query()->create(['name' => 'Ticket Customer', 'email' => 'ticket-customer@example.test', 'password' => 'not-a-real-password']);
        $ticket = SupportTicket::query()->create(['public_id' => 'SUP-TEST12345', 'user_id' => $customer->id, 'category' => 'account', 'subject' => 'Account help request', 'status' => 'open', 'priority' => 'normal']);
        $ticket->messages()->create(['author_user_id' => $customer->id, 'is_staff' => false, 'body' => 'I need assistance with my account.']);
        $this->actingAs($admin)->get('/admin/support?ticket='.$ticket->id)->assertOk()->assertSee($ticket->public_id);
        $this->actingAs($admin)->post('/admin/support/'.$ticket->id.'/reply', ['body' => 'We are reviewing your account request.'])->assertRedirect();
        $this->assertDatabaseHas('support_ticket_messages', ['ticket_id' => $ticket->id, 'is_staff' => true]);
        $this->actingAs($admin)->put('/admin/support/'.$ticket->id.'/status', ['status' => 'in_progress', 'reason' => 'Assigned to the account support team'])->assertRedirect();
        $this->assertDatabaseHas('support_ticket_audits', ['ticket_id' => $ticket->id, 'action' => 'status_changed', 'new_status' => 'in_progress']);
    }

    public function test_unauthorized_customer_cannot_access_admin_ticket_queue(): void
    {
        $customer = User::query()->create(['name' => 'Normal Customer', 'email' => 'normal-support@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($customer)->get('/admin/support')->assertForbidden();
    }
}
