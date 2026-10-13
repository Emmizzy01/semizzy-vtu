<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffPermissionAudit;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class StaffAccessController extends Controller
{
    private const MANAGED_PERMISSION = 'refunds.approve';

    public function dashboard(): InertiaResponse
    {
        return Inertia::render('Admin/Staff/Index');
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = User::query()
            ->select(['id', 'name', 'email', 'created_at'])
            ->with(['permissions' => fn ($permissions) => $permissions
                ->where('permission', self::MANAGED_PERMISSION)
                ->select(['id', 'user_id', 'permission', 'granted_by_user_id', 'created_at'])])
            ->orderBy('name')
            ->orderBy('id');

        if (! empty($validated['search'])) {
            $term = trim($validated['search']);
            $query->where(function ($users) use ($term): void {
                $users->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
                if (ctype_digit($term)) {
                    $users->orWhere('id', (int) $term);
                }
            });
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toISOString(),
                'can_approve_refunds' => $user->permissions->isNotEmpty(),
                'permission_granted_at' => $user->permissions->first()?->created_at?->toISOString(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function audits(): JsonResponse
    {
        $audits = StaffPermissionAudit::query()
            ->with([
                'actor:id,name,email',
                'target:id,name,email',
            ])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (StaffPermissionAudit $audit): array => [
                'id' => $audit->id,
                'actor' => $audit->actor?->name ?? 'Deleted account',
                'actor_email' => $audit->actor?->email,
                'target' => $audit->target?->name ?? 'Deleted account',
                'target_email' => $audit->target?->email,
                'permission' => $audit->permission,
                'action' => $audit->action,
                'reason' => $audit->reason,
                'created_at' => $audit->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $audits]);
    }

    public function grant(Request $request, User $user): JsonResponse
    {
        return $this->changePermission($request, $user, 'granted');
    }

    public function revoke(Request $request, User $user): JsonResponse
    {
        return $this->changePermission($request, $user, 'revoked');
    }

    private function changePermission(Request $request, User $target, string $action): JsonResponse
    {
        $validated = $request->validate([
            'permission' => ['required', 'in:refunds.approve'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $actor = $request->user();
        if (! $actor || $actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot change your own staff permissions.',
            ]);
        }

        $result = DB::transaction(function () use ($actor, $target, $validated, $action): array {
            $permission = (string) $validated['permission'];
            $existing = UserPermission::query()
                ->where('user_id', $target->id)
                ->where('permission', $permission)
                ->lockForUpdate()
                ->first();

            if ($action === 'granted') {
                if ($existing) {
                    return ['changed' => false, 'message' => 'This account already has refund approval access.'];
                }

                UserPermission::query()->create([
                    'user_id' => $target->id,
                    'permission' => $permission,
                    'granted_by_user_id' => $actor->id,
                ]);
            } else {
                if (! $existing) {
                    return ['changed' => false, 'message' => 'This account does not have refund approval access.'];
                }

                $existing->delete();
            }

            StaffPermissionAudit::query()->create([
                'actor_user_id' => $actor->id,
                'target_user_id' => $target->id,
                'permission' => $permission,
                'action' => $action,
                'reason' => trim((string) $validated['reason']),
            ]);

            return ['changed' => true, 'message' => $action === 'granted'
                ? 'Refund approval access granted.'
                : 'Refund approval access revoked.'];
        });

        return response()->json([
            'message' => $result['message'],
            'changed' => $result['changed'],
        ]);
    }
}
