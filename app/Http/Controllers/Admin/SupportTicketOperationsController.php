<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SupportTicketOperationsController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:all,open,in_progress,waiting_customer,resolved,closed']]);
        $query = SupportTicket::query()->with('user:id,name,email')->withCount('messages')->latest('updated_at');
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('public_id', 'like', '%'.addcslashes($search, '\\%_').'%')->orWhere('subject', 'like', '%'.addcslashes($search, '\\%_').'%')->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')));
        $status = $filters['status'] ?? 'all';
        if ($status !== 'all') $query->where('status', $status);
        $tickets = $query->paginate(20)->withQueryString();
        $selectedId = $request->integer('ticket');
        $selected = $selectedId ? SupportTicket::query()->with(['user:id,name,email', 'messages.author:id,name'])->find($selectedId) : null;
        return Inertia::render('Admin/SupportTickets', [
            'tickets' => $tickets->through(fn (SupportTicket $ticket): array => [
                'id' => $ticket->id, 'public_id' => $ticket->public_id, 'user_name' => $ticket->user?->name ?? 'Unavailable account',
                'user_email' => $ticket->user?->email, 'category' => $ticket->category, 'subject' => $ticket->subject,
                'status' => $ticket->status, 'priority' => $ticket->priority, 'messages_count' => $ticket->messages_count,
                'updated_at' => $ticket->updated_at?->toISOString(),
            ]),
            'selected' => $selected ? [
                'id' => $selected->id, 'public_id' => $selected->public_id, 'user_name' => $selected->user?->name ?? 'Unavailable account',
                'user_email' => $selected->user?->email, 'subject' => $selected->subject, 'status' => $selected->status,
                'messages' => $selected->messages->map(fn ($message): array => [
                    'id' => $message->id, 'author' => $message->author?->name ?? 'Unavailable account',
                    'is_staff' => $message->is_staff, 'body' => $message->body, 'created_at' => $message->created_at?->toISOString(),
                ]),
            ] : null,
            'filters' => ['search' => $search, 'status' => $status],
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']]);
        DB::transaction(function () use ($request, $ticket, $data): void {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($locked->status, ['resolved', 'closed'], true), 422, 'Reopen the ticket before replying.');
            $locked->messages()->create(['author_user_id' => $request->user()->id, 'is_staff' => true, 'body' => trim($data['body'])]);
            $old = $locked->status;
            $locked->forceFill(['status' => 'waiting_customer', 'last_reply_at' => now()])->save();
            DB::table('support_ticket_audits')->insert([
                'ticket_id' => $locked->id, 'actor_user_id' => $request->user()->id, 'action' => 'staff_replied',
                'old_status' => $old, 'new_status' => 'waiting_customer', 'metadata' => json_encode([], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }, attempts: 3);
        return redirect()->route('admin.support.index', ['ticket' => $ticket->id])->with('status', 'Customer reply sent.');
    }

    public function updateStatus(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,in_progress,waiting_customer,resolved,closed'], 'reason' => ['required', 'string', 'min:8', 'max:500']]);
        DB::transaction(function () use ($request, $ticket, $data): void {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $old = $locked->status;
            if ($old === $data['status']) return;
            $locked->forceFill(['status' => $data['status']])->save();
            DB::table('support_ticket_audits')->insert([
                'ticket_id' => $locked->id, 'actor_user_id' => $request->user()->id, 'action' => 'status_changed',
                'old_status' => $old, 'new_status' => $data['status'],
                'metadata' => json_encode(['reason' => trim($data['reason'])], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }, attempts: 3);
        return redirect()->route('admin.support.index', ['ticket' => $ticket->id])->with('status', 'Ticket status updated and audited.');
    }
}
