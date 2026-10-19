<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralAttribution extends Model
{
    protected $fillable = [
        'referrer_user_id', 'referred_user_id', 'reviewed_by_user_id',
        'referral_code', 'status', 'reward_minor', 'qualifying_reference',
        'review_note', 'qualified_at', 'rewarded_at',
    ];

    protected function casts(): array
    {
        return ['reward_minor' => 'integer', 'qualified_at' => 'datetime', 'rewarded_at' => 'datetime'];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
