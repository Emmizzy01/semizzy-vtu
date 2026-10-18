<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationServiceProduct extends Model
{
    protected $fillable = [
        'service_type', 'exam_body', 'name', 'code', 'provider',
        'provider_product_code', 'provider_cost_minor', 'price_minor',
        'currency', 'metadata', 'requires_candidate_validation',
        'is_active', 'purchase_approved',
    ];

    protected function casts(): array
    {
        return [
            'provider_cost_minor' => 'integer',
            'price_minor' => 'integer',
            'metadata' => 'array',
            'requires_candidate_validation' => 'boolean',
            'is_active' => 'boolean',
            'purchase_approved' => 'boolean',
        ];
    }
}
