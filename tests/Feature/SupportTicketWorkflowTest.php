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

    public function test_customer_cannot_reply_to_another_users_ticket_by_direct_url(): void
    {
        $owner = User::query()->create(['name' => 'Ticket Owner', 'email' => 'ticket-owner-direct@example.test', 'password' => 'not-a-real-password']);
        $other = User::query()->create(['name' => 'Ticket Outsider', 'email' => 'ticket-outsider-direct@example.test', 'password' => 'not-a-real-password']);
        $ticket = SupportTicket::query()->create([
            'public_id' => 'SUP-OWNERONLY123',
            'user_id' => $owner->id,
            'category' => 'security',
            'subject' => 'Private account security issue',
            'status' => 'open',
            'priority' => 'normal',
        ]);
        $ticket->messages()->create([
            'author_user_id' => $owner->id,
            'is_staff' => false,
            'body' => 'This is a private security issue.',
        ]);

        $this->actingAs($other)->post('/support/'.$ticket->id.'/reply', [
            'body' => 'Trying to reply to another customer ticket.',
        ])->assertNotFound();

        $this->assertDatabaseCount('support_ticket_messages', 1);
        $this->assertDatabaseMissing('support_ticket_messages', [
            'ticket_id' => $ticket->id,
            'author_user_id' => $other->id,
        ]);
        $this->assertDatabaseMissing('support_ticket_audits', [
            'ticket_id' => $ticket->id,
            'actor_user_id' => $other->id,
            'action' => 'customer_replied',
        ]);
    }


    public function test_customer_cannot_mutate_ticket_through_admin_reply_or_status_routes(): void
    {
        $customer = User::query()->create([
            'name' => 'Ticket Route Outsider',
            'email' => 'ticket-route-outsider@example.test',
            'password' => 'not-a-real-password',
        ]);
        $owner = User::query()->create([
            'name' => 'Ticket Route Owner',
            'email' => 'ticket-route-owner@example.test',
            'password' => 'not-a-real-password',
        ]);
        $ticket = SupportTicket::query()->create([
            'public_id' => 'SUP-ROUTEGUARD123',
            'user_id' => $owner->id,
            'category' => 'account',
            'subject' => 'Private route authorization check',
            'status' => 'open',
            'priority' => 'normal',
        ]);
        $ticket->messages()->create([
            'author_user_id' => $owner->id,
            'is_staff' => false,
            'body' => 'Private customer message.',
        ]);

        $this->actingAs($customer)->post('/admin/support/'.$ticket->id.'/reply', [
            'body' => 'Unauthorized staff reply attempt.',
        ])->assertForbidden();

        $this->actingAs($customer)->put('/admin/support/'.$ticket->id.'/status', [
            'status' => 'closed',
            'reason' => 'Unauthorized status change attempt',
        ])->assertForbidden();

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseCount('support_ticket_messages', 1);
        $this->assertDatabaseMissing('support_ticket_audits', [
            'ticket_id' => $ticket->id,
            'actor_user_id' => $customer->id,
            'action' => 'status_changed',
        ]);
    }

}
