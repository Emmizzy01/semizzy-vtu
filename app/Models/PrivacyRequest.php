<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacyRequest extends Model
{
    protected $fillable = ['user_id', 'request_type', 'reason', 'status', 'reviewed_by_user_id', 'review_note', 'reviewed_at', 'fulfilled_at'];
    protected function casts(): array { return ['reviewed_at' => 'datetime', 'fulfilled_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
}
