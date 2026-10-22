<?php

namespace App\Http\Controllers;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;
use App\Services\Addons\AddonImplementationRegistry;
use App\Services\Addons\AddonLifecyclePolicy;
use App\Models\VtuWebsiteRequest;
use App\Models\VtuWebsiteRequestEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class VtuWebsiteRequestController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.vtu_website_requests')->first();
        $addon = PlatformAddon::query()->where('addon_key', 'service.vtu-website-requests')->first();
        $implementation = app(AddonImplementationRegistry::class)->find('service.vtu-website-requests');
        $registered = $implementation !== null && in_array('vtu-website-requests.index', $implementation['operations'], true);
        $available = $addon !== null && app(AddonLifecyclePolicy::class)->assessAdmission(
            (string) $addon->status,
            (string) $addon->compatibility_status,
            $registered,
            true,
            $feature !== null && $feature->is_enabled && $feature->is_implemented,
        )['available'];
        // Keep request history readable even when new submissions are gated.
        $requests = VtuWebsiteRequest::query()->where('user_id', $request->user()->id)->latest()->paginate(10)->withQueryString();

        return Inertia::render('Services/VtuWebsiteRequests', [
            'available' => $available,
            'requests' => $requests->through(fn (VtuWebsiteRequest $item): array => [
                'public_id' => $item->public_id,
                'request_type' => $item->request_type,
                'business_name' => $item->business_name,
                'description' => $item->description,
                'status' => $item->status,
                'admin_note' => $item->admin_note,
                'created_at' => $item->created_at?->toISOString(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.vtu_website_requests')->first();
        $addon = PlatformAddon::query()->where('addon_key', 'service.vtu-website-requests')->first();
        $implementation = app(AddonImplementationRegistry::class)->find('service.vtu-website-requests');
        $registered = $implementation !== null && in_array('vtu-website-requests.store', $implementation['operations'], true);
        $available = $addon !== null && app(AddonLifecyclePolicy::class)->assessAdmission(
            (string) $addon->status,
            (string) $addon->compatibility_status,
            $registered,
            true,
            $feature !== null && $feature->is_enabled && $feature->is_implemented,
        )['available'];
        abort_unless($available, 503, 'VTU website requests are not accepting submissions right now.');

        $data = $request->validate([
            'request_type' => ['required', 'in:new_vtu_site,redesign,api_integration,maintenance,other'],
            'business_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s-]{10,20}$/'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        $item = DB::transaction(function () use ($request, $data): VtuWebsiteRequest {
            $created = VtuWebsiteRequest::query()->create([
                'public_id' => 'VWR-'.Str::upper(Str::random(10)),
                'user_id' => $request->user()->id,
                'request_type' => $data['request_type'],
                'business_name' => isset($data['business_name']) ? trim($data['business_name']) : null,
                'contact_phone' => preg_replace('/[\s-]+/', '', $data['contact_phone']),
                'budget_minor' => isset($data['budget']) ? (int) round((float) $data['budget'] * 100) : null,
                'description' => trim($data['description']),
                'status' => 'submitted',
            ]);
            VtuWebsiteRequestEvent::query()->create([
                'request_id' => $created->id,
                'actor_user_id' => $request->user()->id,
                'action' => 'submitted',
                'new_status' => 'submitted',
                'reason' => 'Customer submitted a VTU website service request.',
                'created_at' => now(),
            ]);

            return $created;
        });

        return redirect()->route('vtu-website-requests.index')->with('status', 'Request '.$item->public_id.' submitted for review.');
    }

    public function adminIndex(Request $request): InertiaResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:all,submitted,in_review,awaiting_customer,approved,rejected,completed'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $query = VtuWebsiteRequest::query()->with('user:id,name,email')->latest();
        $status = $filters['status'] ?? 'all';
        $search = trim((string) ($filters['search'] ?? ''));
        if ($status !== 'all') $query->where('status', $status);
        if ($search !== '') {
            $escaped = addcslashes($search, '\\%_');
            $query->where(fn ($q) => $q->where('public_id', 'like', '%'.$escaped.'%')
                ->orWhere('business_name', 'like', '%'.$escaped.'%')
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$escaped.'%')->orWhere('email', 'like', '%'.$escaped.'%')));
        }
        $items = $query->paginate(20)->withQueryString();

        return Inertia::render('Admin/VtuWebsiteRequests', [
            'requests' => $items->through(fn (VtuWebsiteRequest $item): array => [
                'id' => $item->id, 'public_id' => $item->public_id,
                'user_name' => $item->user?->name ?? 'Unavailable account',
                'user_email' => $item->user?->email,
                'request_type' => $item->request_type, 'business_name' => $item->business_name,
                'contact_phone' => $item->contact_phone, 'budget_minor' => $item->budget_minor,
                'description' => $item->description, 'status' => $item->status,
                'admin_note' => $item->admin_note, 'created_at' => $item->created_at?->toISOString(),
            ]),
            'filters' => ['status' => $status, 'search' => $search],
        ]);
    }

    public function updateStatus(Request $request, VtuWebsiteRequest $websiteRequest): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:submitted,in_review,awaiting_customer,approved,rejected,completed'],
            'admin_note' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $websiteRequest, $data): void {
            $item = VtuWebsiteRequest::query()->whereKey($websiteRequest->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $item->status;
            $newStatus = $data['status'];
            $item->forceFill([
                'status' => $newStatus,
                'admin_note' => trim($data['admin_note']),
                'reviewed_by_user_id' => $request->user()->id,
            ])->save();
            if ($oldStatus !== $newStatus || $item->wasChanged('admin_note')) {
                VtuWebsiteRequestEvent::query()->create([
                    'request_id' => $item->id,
                    'actor_user_id' => $request->user()->id,
                    'action' => 'admin_updated',
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'reason' => trim($data['admin_note']),
                    'created_at' => now(),
                ]);
            }
        }, attempts: 3);

        return redirect()->route('admin.vtu-website-requests.index')->with('status', 'Request status and review note saved.');
    }
}
