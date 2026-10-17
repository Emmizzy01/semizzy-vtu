<?php

namespace App\Models;

use App\Enums\VtuOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VtuOrder extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'wallet_id',
        'vtu_product_id',
        'wallet_transaction_id',
        'idempotency_key',
        'request_hash',
        'service_type',
        'network',
        'product_code',
        'recipient',
        'amount_minor',
        'currency',
        'status',
        'provider',
        'provider_reference',
        'failure_code',
        'dispatch_started_at',
        'completed_at',
        'safe_provider_response',
        'service_details',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'dispatch_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'safe_provider_response' => 'array',
            'service_details' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(VtuProduct::class, 'vtu_product_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function providerAttempts(): HasMany
    {
        return $this->hasMany(VtuProviderAttempt::class, 'vtu_order_id')->orderBy('attempt_number');
    }
}
