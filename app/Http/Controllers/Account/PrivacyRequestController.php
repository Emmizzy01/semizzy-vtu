<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\PrivacyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PrivacyRequestController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Privacy/Requests', [
            'requests' => PrivacyRequest::query()->where('user_id', $request->user()->id)
                ->latest('id')->limit(50)->get(['id', 'request_type', 'reason', 'status', 'review_note', 'created_at', 'reviewed_at', 'fulfilled_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'request_type' => ['required', 'in:access,export,correction,deletion'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $existing = PrivacyRequest::query()->where('user_id', $request->user()->id)
            ->whereIn('status', ['submitted', 'in_review', 'approved'])
            ->where('request_type', $data['request_type'])->exists();
        if ($existing) {
            return back()->withErrors(['request_type' => 'You already have an open request of this type. Please wait for it to be reviewed.']);
        }

        PrivacyRequest::query()->create([
            'user_id' => $request->user()->id,
            'request_type' => $data['request_type'],
            'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
        ]);

        return back()->with('success', 'Privacy request submitted for review. No account data has been deleted or changed automatically.');
    }

    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $payload = [
            'exported_at' => now()->toIso8601String(),
            'scope' => 'Basic account profile and privacy-request history only; this is not a full platform-data export.',
            'profile' => $user->only(['id', 'name', 'email', 'username', 'mobile_number', 'whatsapp_number', 'address', 'date_of_birth', 'sex', 'created_at', 'updated_at']),
            'privacy_requests' => PrivacyRequest::query()->where('user_id', $user->id)
                ->get(['id', 'request_type', 'status', 'reason', 'review_note', 'created_at', 'reviewed_at', 'fulfilled_at']),
        ];

        return response()->json($payload)->withHeaders([
            'Content-Disposition' => 'attachment; filename="semizzy-account-data.json"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
