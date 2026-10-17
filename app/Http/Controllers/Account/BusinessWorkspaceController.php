<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\BusinessWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BusinessWorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $workspaces = BusinessWorkspace::query()
            ->where('status', 'active')
            ->where(function ($query) use ($user): void {
                $query->where('owner_user_id', $user->getKey())
                    ->orWhereHas('memberships', function ($memberships) use ($user): void {
                        $memberships->where('user_id', $user->getKey())
                            ->where('status', 'active');
                    });
            })
            ->with('owner:id,name')
            ->withCount(['memberships as active_members_count' => fn ($query) => $query->where('status', 'active')])
            ->latest('id')
            ->get()
            ->map(fn (BusinessWorkspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'type' => $workspace->type,
                'owner' => $workspace->owner?->name,
                'activeMembers' => (int) $workspace->active_members_count,
                'isOwner' => (int) $workspace->owner_user_id === (int) $user->getKey(),
                'canManage' => $workspace->canManage($user),
            ])
            ->values();

        return Inertia::render('Workspaces/Index', [
            'workspaces' => $workspaces,
            'canCreate' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'type' => ['required', 'string', 'in:business,reseller'],
        ]);

        $baseSlug = Str::slug($validated['name']);
        if ($baseSlug === '') {
            return back()->withErrors(['name' => 'Enter a workspace name containing letters or numbers.']);
        }

        $workspace = DB::transaction(function () use ($user, $validated, $baseSlug): BusinessWorkspace {
            $slug = Str::limit($baseSlug, 65, '') . '-' . $user->getKey();
            $suffix = 2;
            while (BusinessWorkspace::query()->where('slug', $slug)->exists()) {
                $slug = Str::limit($baseSlug, 65, '') . '-' . $user->getKey() . '-' . $suffix;
                $suffix++;
            }

            return BusinessWorkspace::query()->create([
                'owner_user_id' => $user->getKey(),
                'name' => trim($validated['name']),
                'slug' => $slug,
                'type' => $validated['type'],
                'status' => 'active',
                'settings' => [],
            ]);
        });

        return redirect()->route('workspaces.index')
            ->with('success', 'Workspace created successfully: ' . $workspace->name);
    }
}
