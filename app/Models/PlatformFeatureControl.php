<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFeatureControl extends Model
{
    protected $fillable = ['feature_key', 'label', 'category', 'owner', 'description', 'dependencies', 'default_enabled', 'is_enabled', 'is_core', 'is_implemented', 'updated_by_user_id'];
    protected function casts(): array
    {
        return ['dependencies' => 'array', 'default_enabled' => 'boolean', 'is_enabled' => 'boolean', 'is_core' => 'boolean', 'is_implemented' => 'boolean'];
    }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by_user_id'); }
}
