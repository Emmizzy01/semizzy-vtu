<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserLevelPolicy;
use App\Models\UserLevelPolicyAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class UserLevelPolicyController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('Admin/UserLevelPolicies', [
            'policies' => UserLevelPolicy::query()->orderBy('level')->get()->map(fn (UserLevelPolicy $policy): array => [
                'level' => $policy->level, 'label' => match ($policy->level) { 'level_1' => 'Tier 1', 'level_2' => 'Tier 2', 'level_3' => 'Tier 3', 'level_4' => 'Merchant (Tier 4)', default => $policy->label },
                'wallet_balance_limit_minor' => $policy->wallet_balance_limit_minor,
                'max_single_transaction_minor' => $policy->max_single_transaction_minor,
                'daily_transaction_limit_minor' => $policy->daily_transaction_limit_minor,
                'requires_verified_kyc' => $policy->requires_verified_kyc,
                'is_active' => $policy->is_active,
            ])->values(),
        ]);
    }

    public function update(Request $request, string $level): RedirectResponse
    {
        abort_unless(in_array($level, ['level_1', 'level_2', 'level_3', 'level_4'], true), 404);
        $data = $request->validate([
            'wallet_balance_limit_minor' => ['nullable', 'integer', 'min:1', 'max:5000000000'],
            'max_single_transaction_minor' => ['nullable', 'integer', 'min:1', 'max:500000000'],
            'daily_transaction_limit_minor' => ['nullable', 'integer', 'min:1', 'max:5000000000'],
            'requires_verified_kyc' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        if ($data['max_single_transaction_minor'] !== null && $data['daily_transaction_limit_minor'] !== null
            && (int) $data['daily_transaction_limit_minor'] < (int) $data['max_single_transaction_minor']) {
            return back()->withErrors(['daily_transaction_limit_minor' => 'The daily limit cannot be lower than the single-transaction limit.']);
        }
        DB::transaction(function () use ($request, $level, $data): void {
            $policy = UserLevelPolicy::query()->where('level', $level)->lockForUpdate()->firstOrFail();
            $old = [
                'wallet_balance_limit_minor' => $policy->wallet_balance_limit_minor,
                'max_single_transaction_minor' => $policy->max_single_transaction_minor,
                'daily_transaction_limit_minor' => $policy->daily_transaction_limit_minor,
                'requires_verified_kyc' => $policy->requires_verified_kyc,
                'is_active' => $policy->is_active,
            ];
            $new = [
                'wallet_balance_limit_minor' => ($data['wallet_balance_limit_minor'] ?? null) === null ? null : (int) $data['wallet_balance_limit_minor'],
                'max_single_transaction_minor' => ($data['max_single_transaction_minor'] ?? null) === null ? null : (int) $data['max_single_transaction_minor'],
                'daily_transaction_limit_minor' => ($data['daily_transaction_limit_minor'] ?? null) === null ? null : (int) $data['daily_transaction_limit_minor'],
                'requires_verified_kyc' => (bool) $data['requires_verified_kyc'],
                'is_active' => (bool) $data['is_active'],
            ];
            if ($old !== $new) {
                $policy->forceFill([...$new, 'updated_by_user_id' => $request->user()->id])->save();
                UserLevelPolicyAudit::query()->create([
                    'actor_user_id' => $request->user()->id, 'level' => $level,
                    'old_values_hash' => hash('sha256', json_encode($old, JSON_THROW_ON_ERROR)),
                    'new_values_hash' => hash('sha256', json_encode($new, JSON_THROW_ON_ERROR)),
                    'reason' => trim($data['reason']), 'created_at' => now(),
                ]);
            }
        }, attempts: 3);
        return back()->with('status', 'User-level transaction policy saved and audited.');
    }
}
