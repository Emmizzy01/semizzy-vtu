<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KycSubmission extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'document_type', 'evidence_path', 'evidence_sha256',
        'status', 'reviewed_by_user_id', 'evidence_viewed_at', 'evidence_viewed_by_user_id',
        'reviewed_at', 'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'evidence_viewed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function evidenceViewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evidence_viewed_by_user_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(KycSubmissionAudit::class);
    }
}
