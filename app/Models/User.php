<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'whatsapp_number',
        'whatsapp_verified_at',
        'address',
        'date_of_birth',
        'sex',
        'mobile_number',
        'avatar_path',
        'password',
    ];

    protected $appends = ['avatar_url'];

    protected $hidden = [
        'password',
        'remember_token',
        'transaction_pin_hash',
        'transaction_pin_failed_attempts',
        'transaction_pin_locked_until',
        'transaction_pin_changed_at',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'whatsapp_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'transaction_pin_locked_until' => 'datetime',
            'transaction_pin_changed_at' => 'datetime',
            'two_factor_enabled_at' => 'datetime',
        ];
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function businessWorkspaces(): HasMany
    {
        return $this->hasMany(BusinessWorkspace::class, 'owner_user_id');
    }

    public function businessWorkspaceMemberships(): HasMany
    {
        return $this->hasMany(BusinessWorkspaceMember::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function permissionAudits(): HasMany
    {
        return $this->hasMany(StaffPermissionAudit::class, 'actor_user_id');
    }
}
