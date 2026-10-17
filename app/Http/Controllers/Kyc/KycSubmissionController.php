<?php

namespace App\Http\Controllers\Kyc;

use App\Http\Controllers\Controller;
use App\Models\KycSubmission;
use App\Models\KycSubmissionAudit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class KycSubmissionController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $submissions = KycSubmission::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(10)
            ->get(['id', 'document_type', 'status', 'review_reason', 'created_at', 'reviewed_at'])
            ->map(fn (KycSubmission $submission): array => [
                'id' => $submission->id,
                'document_type' => $submission->document_type,
                'status' => $submission->status,
                'review_reason' => $submission->review_reason,
                'created_at' => $submission->created_at?->toISOString(),
                'reviewed_at' => $submission->reviewed_at?->toISOString(),
            ]);

        return Inertia::render('Account/Kyc', [
            'kycStatus' => $request->user()->kyc_status ?: 'unverified',
            'submissions' => $submissions,
            'canSubmit' => ! in_array($request->user()->kyc_status, ['verified'], true)
                && ! KycSubmission::query()->where('user_id', $request->user()->id)->whereIn('status', ['pending', 'manual_review'])->exists(),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->kyc_status === 'verified', 403, 'Your identity is already verified.');
        $data = $request->validate([
            'document_type' => ['required', 'in:nin,bvn,passport,drivers_license,voters_card,other'],
            'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if (KycSubmission::query()->where('user_id', $user->id)->whereIn('status', ['pending', 'manual_review'])->exists()) {
            throw ValidationException::withMessages([
                'evidence' => 'You already have a submission awaiting review. Please wait for the review result.',
            ]);
        }

        $file = $data['evidence'];
        $path = $file->store('kyc-evidence', 'local');
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['evidence' => 'The evidence file could not be stored securely. Please try again.']);
        }

        try {
            DB::transaction(function () use ($user, $data, $file, $path): void {
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_if($lockedUser->kyc_status === 'verified', 403, 'Your identity is already verified.');
                if (KycSubmission::query()->where('user_id', $lockedUser->id)->whereIn('status', ['pending', 'manual_review'])->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['evidence' => 'You already have a submission awaiting review.']);
                }

                $submission = KycSubmission::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $lockedUser->id,
                    'document_type' => $data['document_type'],
                    'evidence_path' => $path,
                    'evidence_sha256' => hash_file('sha256', $file->getRealPath()),
                    'status' => 'pending',
                ]);
                $lockedUser->forceFill(['kyc_status' => 'pending'])->save();
                KycSubmissionAudit::query()->create([
                    'kyc_submission_id' => $submission->id,
                    'actor_user_id' => $lockedUser->id,
                    'event_type' => 'submitted',
                    'outcome' => 'pending',
                    'reason' => 'Identity evidence submitted for review.',
                    'created_at' => now(),
                ]);
            }, attempts: 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('status', 'Identity evidence submitted securely. Your KYC status will change after an authorised review.');
    }

    public function adminIndex(): InertiaResponse
    {
        $page = KycSubmission::query()
            ->with(['user:id,name,email'])
            ->whereIn('status', ['pending', 'manual_review'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/KycReviews', [
            'submissions' => $page->through(fn (KycSubmission $submission): array => [
                'id' => $submission->id,
                'public_id' => $submission->public_id,
                'user' => ['id' => $submission->user->id, 'name' => $submission->user->name, 'email' => $submission->user->email],
                'document_type' => $submission->document_type,
                'status' => $submission->status,
                'evidence_viewed_at' => $submission->evidence_viewed_at?->toISOString(),
                'created_at' => $submission->created_at?->toISOString(),
            ]),
        ]);
    }

    public function evidence(Request $request, KycSubmission $submission)
    {
        abort_if((int) $request->user()->id === (int) $submission->user_id, 403, 'Reviewers cannot access their own identity evidence.');
        abort_unless(in_array($submission->status, ['pending', 'manual_review'], true), 404);
        abort_unless(Storage::disk('local')->exists($submission->evidence_path), 404, 'Evidence file not found.');

        DB::transaction(function () use ($request, $submission): void {
            $locked = KycSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'evidence_viewed_at' => now(),
                'evidence_viewed_by_user_id' => $request->user()->id,
            ])->save();
            KycSubmissionAudit::query()->create([
                'kyc_submission_id' => $locked->id,
                'actor_user_id' => $request->user()->id,
                'event_type' => 'evidence_viewed',
                'outcome' => 'success',
                'reason' => 'Authorised reviewer accessed private evidence.',
                'created_at' => now(),
            ]);
        }, attempts: 3);

        return Storage::disk('local')->download($submission->evidence_path, 'kyc-evidence-'.$submission->id.'.'.pathinfo($submission->evidence_path, PATHINFO_EXTENSION));
    }

    public function review(Request $request, KycSubmission $submission): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected,manual_review'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        abort_if((int) $request->user()->id === (int) $submission->user_id, 403, 'Reviewers cannot approve their own identity evidence.');

        DB::transaction(function () use ($request, $submission, $data): void {
            $locked = KycSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['pending', 'manual_review'], true)) {
                throw ValidationException::withMessages(['decision' => 'This submission has already received a final decision.']);
            }
            if ($data['decision'] === 'approved' && (
                $locked->evidence_viewed_at === null
                || (int) $locked->evidence_viewed_by_user_id !== (int) $request->user()->id
            )) {
                throw ValidationException::withMessages(['decision' => 'Open the private evidence as this reviewer before approving the submission.']);
            }

            $locked->forceFill([
                'status' => $data['decision'],
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'review_reason' => trim($data['reason']),
            ])->save();

            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $user->forceFill(['kyc_status' => match ($data['decision']) {
                'approved' => 'verified',
                'rejected' => 'rejected',
                default => 'manual_review',
            }])->save();

            KycSubmissionAudit::query()->create([
                'kyc_submission_id' => $locked->id,
                'actor_user_id' => $request->user()->id,
                'event_type' => 'reviewed',
                'outcome' => $data['decision'],
                'reason' => trim($data['reason']),
                'created_at' => now(),
            ]);
        }, attempts: 3);

        return back()->with('status', 'KYC review decision recorded with an audit trail.');
    }
}
