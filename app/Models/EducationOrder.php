<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EducationOrder extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'wallet_id', 'education_service_product_id',
        'wallet_transaction_id', 'idempotency_key', 'request_hash', 'provider',
        'provider_reference', 'status', 'amount_minor', 'currency',
        'candidate_details', 'safe_provider_response', 'failure_code',
        'dispatch_started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'candidate_details' => 'array',
            'safe_provider_response' => 'array',
            'dispatch_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EducationServiceProduct::class, 'education_service_product_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(EducationProviderAttempt::class)->orderBy('attempt_number');
    }
}
