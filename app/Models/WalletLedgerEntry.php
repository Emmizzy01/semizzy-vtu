<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WalletLedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'wallet_id',
        'wallet_transaction_id',
        'entry_type',
        'currency',
        'amount_minor',
        'balance_after_minor',
        'memo',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Ledger entries are historical financial evidence. Corrections must be
        // represented by a new compensating transaction, never by rewriting history.
        static::updating(static function (WalletLedgerEntry $entry): void {
            throw new LogicException('Wallet ledger entries are append-only and cannot be updated.');
        });

        static::deleting(static function (WalletLedgerEntry $entry): void {
            throw new LogicException('Wallet ledger entries are append-only and cannot be deleted.');
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }
}
