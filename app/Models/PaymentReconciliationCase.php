<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReconciliationCase extends Model
{
    protected $fillable = [
        'case_key', 'provider', 'reference', 'payment_intent_id', 'case_type',
        'provider_status', 'provider_transaction_id', 'expected_amount_minor',
        'observed_amount_minor', 'expected_currency', 'observed_currency',
        'details', 'status', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'expected_amount_minor' => 'integer',
            'observed_amount_minor' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }
}