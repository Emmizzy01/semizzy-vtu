<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\BusinessWorkspace;
use App\Models\BusinessWorkspaceMember;
use App\Models\User;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BusinessWorkspaceController extends Controller
{
    private const MEMBER_PERMISSIONS = [
        'orders.view',
        'orders.manage',
        'members.view',
        'reports.view',
        'store.manage',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $workspaces = BusinessWorkspace::query()
            ->where('status', 'active')
            ->where(function ($query) use ($user): void {
                $query->where('owner_user_id', $user->getKey())
                    ->orWhereHas('memberships', function ($memberships) use ($user): void {
                        $memberships->where('user_id', $user->getKey())->where('status', 'active');
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

        return Inertia::render('Workspaces/Index', ['workspaces' => $workspaces, 'canCreate' => true]);
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

        DB::transaction(function () use ($user, $validated, $baseSlug): void {
            $slug = Str::limit($baseSlug, 65, '') . '-' . $user->getKey();
            $suffix = 2;
            while (BusinessWorkspace::query()->where('slug', $slug)->exists()) {
                $slug = Str::limit($baseSlug, 65, '') . '-' . $user->getKey() . '-' . $suffix++;
            }
            BusinessWorkspace::query()->create([
                'owner_user_id' => $user->getKey(),
                'name' => trim($validated['name']),
                'slug' => $slug,
                'type' => $validated['type'],
                'status' => 'active',
                'settings' => [],
            ]);
        });

        return redirect()->route('workspaces.index')->with('success', 'Workspace created successfully.');
    }

    public function team(Request $request, BusinessWorkspace $workspace): Response
    {
        $user = $request->user();
        abort_unless($user && $workspace->hasActiveMember($user), 403);
        abort_unless($workspace->status === 'active', 404);
        abort_unless($workspace->canManage($user) || ($workspace->membershipFor($user)?->hasPermission('members.view') ?? false), 403);

        $members = $workspace->memberships()->with('user:id,name,email')->orderBy('id')->get()
            ->map(fn (BusinessWorkspaceMember $membership): array => [
                'id' => $membership->id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
                'role' => $membership->role,
                'status' => $membership->status,
                'permissions' => $membership->permissions ?? [],
                'canManage' => $workspace->canManage($user)
                    && ((int) $workspace->owner_user_id === (int) $user->getKey()
                        || $membership->role === 'agent'),
            ]);

        return Inertia::render('Workspaces/Team', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'type' => $workspace->type],
            'members' => $members,
            'canManage' => $workspace->canManage($user),
            'isOwner' => (int) $workspace->owner_user_id === (int) $user->getKey(),
            'availablePermissions' => self::MEMBER_PERMISSIONS,
        ]);
    }

    public function invite(Request $request, BusinessWorkspace $workspace): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $workspace->canManage($actor), 403);
        abort_unless($workspace->status === 'active', 404);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:agent,manager,admin'],
            'permissions' => ['sometimes', 'array', 'max:10'],
            'permissions.*' => ['string', 'distinct', 'in:' . implode(',', self::MEMBER_PERMISSIONS)],
        ]);

        $isOwner = (int) $workspace->owner_user_id === (int) $actor->getKey();
        if (!$isOwner && $validated['role'] !== 'agent') {
            return back()->withErrors(['role' => 'Managers may invite agents only.']);
        }
        if (!$isOwner && in_array('members.manage', $validated['permissions'] ?? [], true)) {
            return back()->withErrors(['permissions' => 'Managers cannot grant member-management permissions.']);
        }

        $target = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($validated['email'])])->first();
        if (!$target) {
            return back()->withErrors(['email' => 'This email must belong to an existing Semizzy account.']);
        }
        if ((int) $target->getKey() === (int) $workspace->owner_user_id) {
            return back()->withErrors(['email' => 'The workspace owner already has owner access.']);
        }

        $token = Str::random(64);
        $expiresAt = now()->addDays(7);
        $membership = DB::transaction(function () use ($workspace, $target, $validated, $actor, $token, $expiresAt): BusinessWorkspaceMember {
            $membership = BusinessWorkspaceMember::query()
                ->where('business_workspace_id', $workspace->getKey())
                ->where('user_id', $target->getKey())
                ->lockForUpdate()
                ->first();

            if ($membership && $membership->status === 'active') {
                abort(422, 'This user is already an active workspace member.');
            }
            if ($membership) {
                $membership->fill([
                    'role' => $validated['role'],
                    'permissions' => array_values($validated['permissions'] ?? []),
                    'status' => 'invited',
                    'invited_by_user_id' => $actor->getKey(),
                    'accepted_at' => null,
                    'invite_token_hash' => hash('sha256', $token),
                    'invite_expires_at' => $expiresAt,
                ])->save();

                return $membership;
            }

            return BusinessWorkspaceMember::query()->create([
                'business_workspace_id' => $workspace->getKey(),
                'user_id' => $target->getKey(),
                'role' => $validated['role'],
                'permissions' => array_values($validated['permissions'] ?? []),
                'status' => 'invited',
                'invited_by_user_id' => $actor->getKey(),
                'invite_token_hash' => hash('sha256', $token),
                'invite_expires_at' => $expiresAt,
            ]);
        });

        $url = route('workspaces.invitations.show', ['membership' => $membership->getKey(), 'token' => $token]);
        $target->notify(new WorkspaceInvitationNotification(
            $workspace->name,
            $actor->name,
            $url,
            $expiresAt->toDayDateTimeString(),
        ));

        return back()->with('success', 'Invitation sent. It expires in 7 days.');
    }

    public function showInvitation(Request $request, BusinessWorkspaceMember $membership, string $token): Response
    {
        $user = $request->user();
        abort_unless($user && (int) $membership->user_id === (int) $user->getKey(), 403);
        abort_unless($this->validInvitation($membership, $token), 404);

        $workspace = $membership->workspace;
        abort_unless($workspace && $workspace->status === 'active', 404);

        return Inertia::render('Workspaces/AcceptInvitation', [
            'workspace' => ['name' => $workspace->name, 'type' => $workspace->type],
            'membershipId' => $membership->getKey(),
            'token' => $token,
            'role' => $membership->role,
            'expiresAt' => $membership->invite_expires_at?->toIso8601String(),
        ]);
    }

    public function acceptInvitation(Request $request, BusinessWorkspaceMember $membership, string $token): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && (int) $membership->user_id === (int) $user->getKey(), 403);

        DB::transaction(function () use ($membership, $token, $user): void {
            $locked = BusinessWorkspaceMember::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->user_id === (int) $user->getKey(), 403);
            abort_unless($this->validInvitation($locked, $token), 404);
            $workspace = BusinessWorkspace::query()->whereKey($locked->business_workspace_id)->lockForUpdate()->firstOrFail();
            abort_unless($workspace->status === 'active', 404);

            $locked->forceFill([
                'status' => 'active',
                'accepted_at' => now(),
                'invite_token_hash' => null,
                'invite_expires_at' => null,
            ])->save();
        });

        return redirect()->route('workspaces.index')->with('success', 'You joined the workspace.');
    }

    public function updateMember(Request $request, BusinessWorkspace $workspace, BusinessWorkspaceMember $membership): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $workspace->canManage($actor), 403);
        abort_unless((int) $membership->business_workspace_id === (int) $workspace->getKey(), 404);
        abort_unless($workspace->status === 'active', 404);

        $isOwner = (int) $workspace->owner_user_id === (int) $actor->getKey();
        if (!$isOwner && $membership->role !== 'agent') {
            abort(403);
        }

        $validated = $request->validate([
            'role' => ['sometimes', 'required', 'string', 'in:agent,manager,admin'],
            'permissions' => ['sometimes', 'array', 'max:10'],
            'permissions.*' => ['string', 'distinct', 'in:' . implode(',', self::MEMBER_PERMISSIONS)],
        ]);
        if (!$isOwner && isset($validated['role']) && $validated['role'] !== 'agent') {
            return back()->withErrors(['role' => 'Managers may assign agent roles only.']);
        }
        if (!$isOwner && in_array('members.manage', $validated['permissions'] ?? [], true)) {
            return back()->withErrors(['permissions' => 'Managers cannot grant member-management permissions.']);
        }
        $membership->fill($validated)->save();

        return back()->with('success', 'Workspace membership updated.');
    }

    public function removeMember(Request $request, BusinessWorkspace $workspace, BusinessWorkspaceMember $membership): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $workspace->canManage($actor), 403);
        abort_unless((int) $membership->business_workspace_id === (int) $workspace->getKey(), 404);
        abort_unless((int) $membership->user_id !== (int) $workspace->owner_user_id, 403);
        $isOwner = (int) $workspace->owner_user_id === (int) $actor->getKey();
        if (!$isOwner && $membership->role !== 'agent') {
            abort(403);
        }

        $membership->delete();

        return back()->with('success', 'Workspace membership removed.');
    }

    private function validInvitation(BusinessWorkspaceMember $membership, string $token): bool
    {
        return $membership->status === 'invited'
            && is_string($membership->invite_token_hash)
            && $membership->invite_token_hash !== ''
            && $membership->invite_expires_at !== null
            && $membership->invite_expires_at->isFuture()
            && hash_equals($membership->invite_token_hash, hash('sha256', $token));
    }
}
