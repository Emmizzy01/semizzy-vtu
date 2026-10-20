<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserVirtualAccountDeposit extends Model
{
    protected $fillable = [
        'user_virtual_account_id', 'user_id', 'provider', 'provider_transaction_id',
        'provider_reference', 'amount_minor', 'currency', 'status',
        'wallet_transaction_id', 'payload_hash', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function virtualAccount(): BelongsTo
    {
        return $this->belongsTo(UserVirtualAccount::class, 'user_virtual_account_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
