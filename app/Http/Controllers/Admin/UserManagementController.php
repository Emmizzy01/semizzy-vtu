<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRoleAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class UserManagementController extends Controller
{
    private const TYPES = ['customer', 'vendor', 'reseller', 'agent', 'business_team', 'support', 'finance', 'compliance', 'administrator'];
    private const LEVELS = ['level_1', 'level_2', 'level_3', 'level_4'];
    private const KYC = ['unverified', 'pending', 'verified', 'rejected', 'manual_review'];

    public function index(Request $request): InertiaResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:all,customer,vendor,reseller,agent,business_team,support,finance,compliance,administrator'],
            'level' => ['nullable', 'string', 'in:all,level_1,level_2,level_3,level_4'],
            'kyc' => ['nullable', 'string', 'in:all,unverified,pending,verified,rejected,manual_review'],
        ]);
        $query = User::query()->select(['id', 'name', 'email', 'user_type', 'user_level', 'kyc_status', 'account_status', 'account_status_reason', 'account_status_changed_at', 'created_at'])->orderByDesc('id');
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        if (($filters['type'] ?? 'all') !== 'all') $query->where('user_type', $filters['type']);
        if (($filters['level'] ?? 'all') !== 'all') $query->where('user_level', $filters['level']);
        if (($filters['kyc'] ?? 'all') !== 'all') $query->where('kyc_status', $filters['kyc']);
        $page = $query->paginate(20)->withQueryString();
        return Inertia::render('Admin/Users/Index', [
            'users' => $page->through(fn (User $user): array => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'user_type' => $user->user_type, 'user_level' => $user->user_level,
                'kyc_status' => $user->kyc_status, 'account_status' => $user->account_status, 'account_status_reason' => $user->account_status_reason, 'account_status_changed_at' => $user->account_status_changed_at?->toISOString(), 'created_at' => $user->created_at?->toISOString(),
            ]),
            'filters' => ['search' => $search, 'type' => $filters['type'] ?? 'all', 'level' => $filters['level'] ?? 'all', 'kyc' => $filters['kyc'] ?? 'all'],
            'options' => ['types' => self::TYPES, 'levels' => self::LEVELS, 'kyc' => self::KYC],
        ]);
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'account_status' => ['required', 'in:active,suspended'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        abort_if((int) $request->user()->id === (int) $user->id, 403, 'You cannot suspend or reactivate your own account.');
        DB::transaction(function () use ($request, $user, $data): void {
            $target = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $target->account_status ?: 'active';
            if ($oldStatus === $data['account_status']) return;
            $target->forceFill([
                'account_status' => $data['account_status'],
                'account_status_reason' => trim($data['reason']),
                'account_status_changed_at' => now(),
            ])->save();
            DB::table('user_account_status_audits')->insert([
                'actor_user_id' => $request->user()->id,
                'target_user_id' => $target->id,
                'old_status' => $oldStatus,
                'new_status' => $data['account_status'],
                'reason' => trim($data['reason']),
                'created_at' => now(),
            ]);
        }, attempts: 3);
        return back()->with('status', 'Account status updated and recorded in the audit trail.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'user_type' => ['required', 'in:customer,vendor,reseller,agent,business_team,support,finance,compliance,administrator'],
            'user_level' => ['required', 'in:level_1,level_2,level_3,level_4'],
            'kyc_status' => ['required', 'in:unverified,pending,verified,rejected,manual_review'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        abort_if((int) $request->user()->id === (int) $user->id, 403, 'You cannot change your own account classification.');
        DB::transaction(function () use ($request, $user, $data): void {
            $target = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $old = ['user_type' => $target->user_type ?: 'customer', 'user_level' => $target->user_level ?: 'level_1', 'kyc_status' => $target->kyc_status ?: 'unverified'];
            $new = ['user_type' => $data['user_type'], 'user_level' => $data['user_level'], 'kyc_status' => $data['kyc_status']];
            if ($old !== $new) {
                $target->forceFill($new)->save();
                UserRoleAudit::query()->create([
                    'actor_user_id' => $request->user()->id, 'target_user_id' => $target->id,
                    'old_user_type' => $old['user_type'], 'new_user_type' => $new['user_type'],
                    'old_user_level' => $old['user_level'], 'new_user_level' => $new['user_level'],
                    'old_kyc_status' => $old['kyc_status'], 'new_kyc_status' => $new['kyc_status'],
                    'reason' => trim($data['reason']), 'created_at' => now(),
                ]);
            }
        }, attempts: 3);
        return back()->with('status', 'User type, level and KYC status saved. Existing permissions and financial controls remain independently enforced.');
    }
}
