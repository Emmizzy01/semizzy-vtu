<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VtuProviderAttempt extends Model
{
    protected $fillable = [
        'vtu_order_id', 'attempt_number', 'provider', 'outcome',
        'provider_reference', 'failure_code', 'definitive_failure',
        'request_fingerprint', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'definitive_failure' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(VtuOrder::class, 'vtu_order_id');
    }
}
