<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'whatsapp_number',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'transaction_pin_hash',
        'transaction_pin_failed_attempts',
        'transaction_pin_locked_until',
        'transaction_pin_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'whatsapp_verified_at' => 'datetime',
            'password' => 'hashed',
            'transaction_pin_locked_until' => 'datetime',
            'transaction_pin_changed_at' => 'datetime',
        ];
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
