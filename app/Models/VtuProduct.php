<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VtuProduct extends Model
{
    protected $fillable = [
        'service_type',
        'network',
        'code',
        'name',
        'price_minor',
        'face_value_minor',
        'currency',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'face_value_minor' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(VtuOrder::class);
    }
}
