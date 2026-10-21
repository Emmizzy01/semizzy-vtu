<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledServicePurchase extends Model
{
    protected $fillable = [
        'user_id', 'service_type', 'product_code', 'recipient', 'amount_minor',
        'currency', 'service_details', 'frequency', 'next_run_at', 'last_run_at',
        'idempotency_key', 'request_hash',
        'status', 'last_order_public_id', 'last_result', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'service_details' => 'array',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
