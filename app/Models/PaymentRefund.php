<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    protected $table = 'payment_refunds';

    protected $fillable = [
        'public_id', 'payment_intent_id', 'requested_by_user_id', 'approved_by_user_id',
        'wallet_debit_transaction_id', 'idempotency_key', 'amount_minor', 'reason',
        'status', 'status_changed_at', 'provider_refund_reference', 'provider_status', 'metadata',
        'approved_at', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'approved_at' => 'datetime',
            'settled_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function walletDebitTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_debit_transaction_id');
    }
}
