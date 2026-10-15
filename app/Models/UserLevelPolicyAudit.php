<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLevelPolicyAudit extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_user_id', 'level', 'old_values_hash', 'new_values_hash', 'reason', 'created_at'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
