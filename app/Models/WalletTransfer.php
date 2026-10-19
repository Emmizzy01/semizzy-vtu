<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransfer extends Model
{
    protected $fillable = [
        'public_id', 'sender_user_id', 'recipient_user_id', 'currency',
        'amount_minor', 'status', 'idempotency_key', 'request_hash',
        'sender_transaction_id', 'recipient_transaction_id', 'description',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function senderTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'sender_transaction_id');
    }

    public function recipientTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'recipient_transaction_id');
    }
}
