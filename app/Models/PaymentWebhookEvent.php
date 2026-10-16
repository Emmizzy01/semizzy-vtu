<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'provider', 'event_id', 'payload_hash', 'event_type',
        'outcome', 'payment_intent_id', 'safe_payload', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'safe_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }
}