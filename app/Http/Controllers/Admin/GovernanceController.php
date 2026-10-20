<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DataRetentionPolicy;
use App\Models\PlatformSettingAudit;
use App\Models\PrivacyRequest;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class GovernanceController extends Controller
{
    private const RETENTION_DEFAULTS = [
        'authentication_security_logs' => 365,
        'financial_ledger_and_payment_records' => 2555,
        'kyc_and_identity_records' => 2555,
        'support_and_privacy_requests' => 730,
        'provider_diagnostic_logs' => 180,
    ];

    public function index(): InertiaResponse
    {
        foreach (self::RETENTION_DEFAULTS as $category => $days) {
            DataRetentionPolicy::query()->firstOrCreate(
                ['record_category' => $category],
                ['retention_days' => $days, 'enabled' => false, 'requires_legal_review' => true,
                 'notes' => 'Policy configured but automatic deletion is disabled pending legal/compliance approval.']
            );
        }

        return Inertia::render('Admin/Governance/Index', [
            'privacyRequests' => PrivacyRequest::query()->with(['user:id,name,email', 'reviewer:id,name'])
                ->latest('id')->limit(100)->get()->map(fn (PrivacyRequest $item): array => [
                    'id' => $item->id, 'user' => $item->user?->name ?? 'Deleted account',
                    'email' => $item->user?->email, 'type' => $item->request_type, 'reason' => $item->reason,
                    'status' => $item->status, 'reviewNote' => $item->review_note,
                    'createdAt' => $item->created_at?->toIso8601String(),
                ]),
            'retentionPolicies' => DataRetentionPolicy::query()->orderBy('record_category')->get()->map(fn (DataRetentionPolicy $item): array => [
                'id' => $item->id, 'category' => $item->record_category, 'days' => $item->retention_days,
                'enabled' => $item->enabled, 'requiresLegalReview' => $item->requires_legal_review, 'notes' => $item->notes,
            ]),
            'incidents' => SecurityIncident::query()->with(['reporter:id,name', 'assignee:id,name'])
                ->latest('id')->limit(100)->get()->map(fn (SecurityIncident $item): array => [
                    'id' => $item->id, 'reference' => $item->reference, 'title' => $item->title,
                    'description' => $item->description, 'severity' => $item->severity, 'status' => $item->status,
                    'category' => $item->category, 'reporter' => $item->reporter?->name ?? 'Deleted account',
                    'assignee' => $item->assignee?->name, 'resolutionNotes' => $item->resolution_notes,
                    'createdAt' => $item->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function reviewPrivacyRequest(Request $request, PrivacyRequest $privacyRequest): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:in_review,approved,rejected,fulfilled'],
            'review_note' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $privacyRequest, $data): void {
            $item = PrivacyRequest::query()->whereKey($privacyRequest->id)->lockForUpdate()->firstOrFail();
            if (in_array($item->status, ['rejected', 'fulfilled'], true)) {
                throw ValidationException::withMessages(['status' => 'Closed privacy requests cannot be changed. Submit a new request if further action is needed.']);
            }
            if ($data['status'] === 'fulfilled' && !in_array($item->status, ['approved', 'in_review'], true)) {
                throw ValidationException::withMessages(['status' => 'Review and approve the request before marking it fulfilled.']);
            }
            if ($data['status'] === 'fulfilled' && in_array($item->request_type, ['deletion', 'correction'], true)) {
                throw ValidationException::withMessages(['status' => 'This request requires its approved fulfilment procedure; the workflow cannot declare it fulfilled automatically.']);
            }
            $old = $item->status;
            $item->forceFill([
                'status' => $data['status'],
                'review_note' => trim($data['review_note']),
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'fulfilled_at' => $data['status'] === 'fulfilled' ? now() : $item->fulfilled_at,
            ])->save();
            PlatformSettingAudit::query()->create([
                'actor_user_id' => $request->user()->id,
                'setting_key' => 'privacy.request.'.$item->id,
                'old_value_hash' => hash('sha256', $old),
                'new_value_hash' => hash('sha256', $item->status),
                'reason' => substr('Privacy request workflow: '.trim($data['review_note']), 0, 500),
            ]);
        });

        return back()->with('success', 'Privacy request status updated and audited.');
    }

    public function updateRetention(Request $request, DataRetentionPolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'retention_days' => ['required', 'integer', 'min:1', 'max:36500'],
            'enabled' => ['required', 'boolean'],
            'requires_legal_review' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $old = [$policy->retention_days, $policy->enabled, $policy->requires_legal_review];
        $policy->forceFill([
            'retention_days' => $data['retention_days'],
            // A retention policy is configuration only. Automated deletion is deliberately not implemented.
            'enabled' => false,
            'requires_legal_review' => true,
            'notes' => trim((string) ($data['notes'] ?? '')).' [Automation disabled; legal review required.]',
            'updated_by_user_id' => $request->user()->id,
        ])->save();

        PlatformSettingAudit::query()->create([
            'actor_user_id' => $request->user()->id,
            'setting_key' => 'retention.policy.'.$policy->record_category,
            'old_value_hash' => hash('sha256', json_encode($old, JSON_THROW_ON_ERROR)),
            'new_value_hash' => hash('sha256', json_encode([$policy->retention_days, false, true], JSON_THROW_ON_ERROR)),
            'reason' => trim($data['reason']).' Automatic deletion remains disabled pending approved legal review.',
        ]);

        return back()->with('success', 'Retention policy saved. Automated deletion remains disabled.');
    }

    public function createIncident(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'severity' => ['required', 'in:low,medium,high,critical'],
            'category' => ['required', 'in:privacy,account_security,financial,provider,availability,other'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $incident = SecurityIncident::query()->create([
                'reference' => 'SEC-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
                'title' => trim($data['title']), 'description' => trim($data['description']),
                'severity' => $data['severity'], 'category' => $data['category'],
                'status' => 'open', 'reported_by_user_id' => $request->user()->id,
            ]);
            SecurityIncidentEvent::query()->create([
                'incident_id' => $incident->id, 'actor_user_id' => $request->user()->id,
                'event_type' => 'reported', 'note' => 'Incident created through governance workflow.',
                'metadata' => ['severity' => $incident->severity, 'category' => $incident->category],
            ]);
        });

        return back()->with('success', 'Security incident recorded.');
    }

    public function updateIncident(Request $request, SecurityIncident $incident): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,investigating,contained,resolved,closed'],
            'resolution_notes' => ['required', 'string', 'min:8', 'max:5000'],
        ]);
        DB::transaction(function () use ($request, $incident, $data): void {
            $item = SecurityIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ($item->status === 'closed') {
                throw ValidationException::withMessages(['status' => 'Closed incidents are immutable. Create a follow-up incident for new findings.']);
            }
            if ($data['status'] === 'closed' && !in_array($item->status, ['resolved'], true)) {
                throw ValidationException::withMessages(['status' => 'Resolve the incident before closing it.']);
            }
            $old = $item->status;
            $item->forceFill([
                'status' => $data['status'], 'resolution_notes' => trim($data['resolution_notes']),
                'assigned_to_user_id' => $item->assigned_to_user_id ?? $request->user()->id,
                'contained_at' => $data['status'] === 'contained' ? ($item->contained_at ?? now()) : $item->contained_at,
                'closed_at' => $data['status'] === 'closed' ? now() : $item->closed_at,
                'closed_by_user_id' => $data['status'] === 'closed' ? $request->user()->id : $item->closed_by_user_id,
            ])->save();
            SecurityIncidentEvent::query()->create([
                'incident_id' => $item->id, 'actor_user_id' => $request->user()->id,
                'event_type' => 'status_changed', 'note' => trim($data['resolution_notes']),
                'metadata' => ['from' => $old, 'to' => $item->status],
            ]);
            PlatformSettingAudit::query()->create([
                'actor_user_id' => $request->user()->id,
                'setting_key' => 'security.incident.'.$item->reference,
                'old_value_hash' => hash('sha256', $old),
                'new_value_hash' => hash('sha256', $item->status),
                'reason' => substr(trim($data['resolution_notes']), 0, 500),
            ]);
        });

        return back()->with('success', 'Incident status and audit timeline updated.');
    }
}
