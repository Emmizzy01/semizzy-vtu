<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceBeneficiary extends Model
{
    protected $fillable = ['user_id', 'service_type', 'label', 'recipient', 'network', 'product_code', 'service_details'];

    protected function casts(): array
    {
        return ['service_details' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
