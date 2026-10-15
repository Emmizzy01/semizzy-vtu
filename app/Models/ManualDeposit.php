<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualDeposit extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'wallet_id', 'amount_minor', 'transfer_reference',
        'evidence_path', 'status', 'evidence_viewed_at', 'evidence_viewed_by_user_id',
        'reviewed_by_user_id', 'reviewed_at', 'review_reason',
        'credited_wallet_transaction_id', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'evidence_viewed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
    public function walletTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class, 'credited_wallet_transaction_id'); }
    public function audits(): HasMany { return $this->hasMany(ManualDepositAudit::class); }
}
