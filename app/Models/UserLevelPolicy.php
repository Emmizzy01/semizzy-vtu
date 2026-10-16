<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLevelPolicy extends Model
{
    protected $fillable = ['level', 'label', 'wallet_balance_limit_minor', 'max_single_transaction_minor', 'daily_transaction_limit_minor', 'requires_verified_kyc', 'is_active', 'updated_by_user_id'];
    protected function casts(): array
    {
        return ['wallet_balance_limit_minor' => 'integer', 'max_single_transaction_minor' => 'integer', 'daily_transaction_limit_minor' => 'integer', 'requires_verified_kyc' => 'boolean', 'is_active' => 'boolean'];
    }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by_user_id'); }
}
