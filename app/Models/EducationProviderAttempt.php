<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EducationProviderAttempt extends Model
{
    protected $fillable = [
        'education_order_id', 'attempt_number', 'provider', 'outcome',
        'request_fingerprint', 'provider_reference', 'message_code',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(EducationOrder::class, 'education_order_id');
    }
}
