<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VtuWebsiteRequest extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'request_type', 'business_name', 'contact_phone',
        'budget_minor', 'description', 'status', 'admin_note', 'reviewed_by_user_id',
    ];

    protected function casts(): array
    {
        return ['budget_minor' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VtuWebsiteRequestEvent::class, 'request_id')->orderBy('created_at');
    }
}
