<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRoleAudit extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_user_id', 'target_user_id', 'old_user_type', 'new_user_type', 'old_user_level', 'new_user_level', 'old_kyc_status', 'new_kyc_status', 'reason', 'created_at'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
    public function target(): BelongsTo { return $this->belongsTo(User::class, 'target_user_id'); }
}
