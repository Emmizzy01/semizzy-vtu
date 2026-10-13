<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $fillable = [
        'public_id',
        'wallet_id',
        'idempotency_key',
        'request_hash',
        'type',
        'status',
        'currency',
        'amount_minor',
        'balance_before_minor',
        'balance_after_minor',
        'reference',
        'description',
        'metadata',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_before_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'metadata' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function ledgerEntry(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(WalletLedgerEntry::class);
    }
}
