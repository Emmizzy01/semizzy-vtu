<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserVirtualAccount extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'provider', 'provider_customer_reference',
        'provider_account_reference', 'bank_code', 'bank_name', 'account_name',
        'account_number', 'currency', 'status', 'verified_at', 'consented_at', 'disabled_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'consented_at' => 'datetime',
            'disabled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
