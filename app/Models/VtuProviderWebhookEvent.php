<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VtuProviderWebhookEvent extends Model
{
    protected $fillable = [
        'provider', 'event_id', 'event_type', 'provider_reference', 'payload',
        'payload_sha256', 'received_at', 'last_attempted_at', 'processed_at', 'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
