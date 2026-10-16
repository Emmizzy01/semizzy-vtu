<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SecurityEventsController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:60'],
            'outcome' => ['nullable', 'string', 'in:all,success,failed,locked,rejected'],
        ]);
        $query = DB::table('transaction_security_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->select(['e.id', 'e.user_id', 'e.event_type', 'e.outcome', 'e.context', 'e.created_at', 'u.name as user_name', 'u.email as user_email'])
            ->orderByDesc('e.id');
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('u.name', 'like', '%'.$search.'%')->orWhere('u.email', 'like', '%'.$search.'%')->orWhere('e.event_type', 'like', '%'.$search.'%'));
        if (! empty($filters['event'])) $query->where('e.event_type', $filters['event']);
        if (($filters['outcome'] ?? 'all') !== 'all') $query->where('e.outcome', $filters['outcome']);
        $page = $query->paginate(30)->withQueryString();
        return Inertia::render('Admin/SecurityEvents', [
            'events' => $page->through(fn ($event): array => [
                'id' => $event->id, 'user_id' => $event->user_id,
                'user_name' => $event->user_name ?: 'Deleted or unavailable account',
                'user_email' => $event->user_email, 'event_type' => $event->event_type,
                'outcome' => $event->outcome, 'context' => $event->context ? json_decode($event->context, true) : [],
                'created_at' => $event->created_at,
            ]),
            'filters' => ['search' => $search, 'event' => $filters['event'] ?? '', 'outcome' => $filters['outcome'] ?? 'all'],
        ]);
    }
}
