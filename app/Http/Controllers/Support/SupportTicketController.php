<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SupportTicketController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $tickets = SupportTicket::query()->where('user_id', $request->user()->id)->withCount('messages')->latest('updated_at')->paginate(10)->withQueryString();
        $selectedId = $request->integer('ticket');
        $selected = $selectedId ? SupportTicket::query()->where('user_id', $request->user()->id)->whereKey($selectedId)->with('messages.author:id,name')->first() : null;
        return Inertia::render('Support/Index', [
            'tickets' => $tickets->through(fn (SupportTicket $ticket): array => [
                'id' => $ticket->id, 'public_id' => $ticket->public_id, 'category' => $ticket->category,
                'subject' => $ticket->subject, 'status' => $ticket->status, 'priority' => $ticket->priority,
                'messages_count' => $ticket->messages_count, 'updated_at' => $ticket->updated_at?->toISOString(),
            ]),
            'selected' => $selected ? [
                'id' => $selected->id, 'public_id' => $selected->public_id, 'subject' => $selected->subject,
                'status' => $selected->status, 'messages' => $selected->messages->map(fn ($message): array => [
                    'id' => $message->id, 'author' => $message->author?->name ?? 'Unavailable account',
                    'is_staff' => $message->is_staff, 'body' => $message->body, 'created_at' => $message->created_at?->toISOString(),
                ]),
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'in:wallet,deposit,vtu,account,security,other'],
            'subject' => ['required', 'string', 'min:5', 'max:120'],
            'body' => ['required', 'string', 'min:10', 'max:4000'],
        ]);
        $ticket = DB::transaction(function () use ($request, $data): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'public_id' => 'SUP-'.Str::upper(Str::random(10)), 'user_id' => $request->user()->id,
                'category' => $data['category'], 'subject' => trim($data['subject']), 'status' => 'open',
                'priority' => 'normal', 'last_reply_at' => now(),
            ]);
            $ticket->messages()->create(['author_user_id' => $request->user()->id, 'is_staff' => false, 'body' => trim($data['body'])]);
            DB::table('support_ticket_audits')->insert([
                'ticket_id' => $ticket->id, 'actor_user_id' => $request->user()->id, 'action' => 'created',
                'old_status' => null, 'new_status' => 'open', 'metadata' => json_encode(['category' => $data['category']], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            return $ticket;
        });
        return redirect()->route('support.index', ['ticket' => $ticket->id])->with('status', 'Support ticket created.');
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        abort_unless((int) $ticket->user_id === (int) $request->user()->id, 404);
        abort_if(in_array($ticket->status, ['resolved', 'closed'], true), 422, 'This ticket is closed. Create a new ticket if you still need help.');
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']]);
        DB::transaction(function () use ($request, $ticket, $data): void {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($locked->status, ['resolved', 'closed'], true), 422, 'This ticket is closed.');
            $locked->messages()->create(['author_user_id' => $request->user()->id, 'is_staff' => false, 'body' => trim($data['body'])]);
            $old = $locked->status;
            $locked->forceFill(['status' => 'open', 'last_reply_at' => now()])->save();
            DB::table('support_ticket_audits')->insert([
                'ticket_id' => $locked->id, 'actor_user_id' => $request->user()->id, 'action' => 'customer_replied',
                'old_status' => $old, 'new_status' => 'open', 'metadata' => json_encode([], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }, attempts: 3);
        return redirect()->route('support.index', ['ticket' => $ticket->id])->with('status', 'Reply sent.');
    }
}
