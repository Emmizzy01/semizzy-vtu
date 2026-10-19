<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankBeneficiary extends Model
{
    protected $fillable = [
        'user_id', 'bank_code', 'bank_name', 'account_number',
        'account_number_last4', 'account_name', 'provider_recipient_code',
        'verification_status', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
