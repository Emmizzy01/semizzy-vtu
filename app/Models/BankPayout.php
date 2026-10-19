<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankPayout extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'bank_beneficiary_id', 'currency',
        'amount_minor', 'fee_minor', 'status', 'idempotency_key',
        'request_hash', 'provider_reference', 'provider_transfer_code',
        'failure_reason', 'wallet_transaction_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(BankBeneficiary::class, 'bank_beneficiary_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
