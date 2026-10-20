<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAddon extends Model
{
    protected $fillable = [
        'addon_key', 'name', 'version', 'minimum_core_version', 'description',
        'dependencies', 'status', 'is_core_owned', 'compatibility_status',
        'compatibility_message', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['dependencies' => 'array', 'is_core_owned' => 'boolean'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
