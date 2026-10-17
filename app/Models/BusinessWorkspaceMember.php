<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessWorkspaceMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_workspace_id',
        'user_id',
        'role',
        'permissions',
        'status',
        'invited_by_user_id',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(BusinessWorkspace::class, 'business_workspace_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->status === 'active'
            && is_array($this->permissions)
            && in_array($permission, $this->permissions, true);
    }
}
