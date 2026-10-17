<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessWorkspace extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_user_id',
        'name',
        'slug',
        'type',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessWorkspaceMember::class);
    }

    public function storefront(): HasOne
    {
        return $this->hasOne(BusinessWorkspaceStorefront::class, 'business_workspace_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_workspace_members')
            ->withPivot(['role', 'permissions', 'status', 'invited_by_user_id', 'accepted_at'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function membershipFor(User $user): ?BusinessWorkspaceMember
    {
        return $this->memberships()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->first();
    }

    public function canManage(User $user): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ((int) $this->owner_user_id === (int) $user->getKey()) {
            return true;
        }

        $membership = $this->membershipFor($user);

        return $membership !== null && in_array($membership->role, ['admin', 'manager'], true);
    }

    public function canManageStorefront(User $user): bool
    {
        if ($this->canManage($user)) {
            return true;
        }

        return $this->membershipFor($user)?->hasPermission('store.manage') ?? false;
    }

    public function hasActiveMember(User $user): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return (int) $this->owner_user_id === (int) $user->getKey()
            || $this->memberships()
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->exists();
    }
}
