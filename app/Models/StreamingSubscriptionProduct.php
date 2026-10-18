<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreamingSubscriptionProduct extends Model
{
    protected $fillable = [
        'platform', 'name', 'code', 'provider', 'provider_product_code',
        'duration', 'price_minor', 'currency', 'metadata', 'is_active', 'purchase_approved',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'purchase_approved' => 'boolean',
        ];
    }
}
