<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VtuProduct extends Model
{
    protected $fillable = [
        'service_type',
        'provider_key',
        'provider_product_code',
        'provider_cost_minor',
        'data_type',
        'validity',
        'pricing_by_user_category',
        'commission_by_user_category',
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
            'provider_cost_minor' => 'integer',
            'pricing_by_user_category' => 'array',
            'commission_by_user_category' => 'array',
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
