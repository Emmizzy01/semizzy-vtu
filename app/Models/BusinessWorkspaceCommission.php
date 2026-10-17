<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessWorkspaceCommission extends Model
{
    protected $fillable = [
        'business_workspace_storefront_id',
        'vtu_order_id',
        'beneficiary_user_id',
        'gross_amount_minor',
        'rate_basis_points',
        'commission_amount_minor',
        'status',
        'settled_wallet_transaction_id',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount_minor' => 'integer',
            'rate_basis_points' => 'integer',
            'commission_amount_minor' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(BusinessWorkspaceStorefront::class, 'business_workspace_storefront_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(VtuOrder::class, 'vtu_order_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function settledWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'settled_wallet_transaction_id');
    }
}
