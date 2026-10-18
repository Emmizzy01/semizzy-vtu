<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VtuWebsiteRequestEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['request_id', 'actor_user_id', 'action', 'old_status', 'new_status', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(VtuWebsiteRequest::class, 'request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
