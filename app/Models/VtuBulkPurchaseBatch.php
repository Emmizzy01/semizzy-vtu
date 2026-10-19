<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VtuBulkPurchaseBatch extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'batch_key', 'request_hash', 'status',
        'item_count', 'total_amount_minor', 'currency', 'started_at',
        'completed_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'item_count' => 'integer',
            'total_amount_minor' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VtuBulkPurchaseItem::class, 'batch_id')->orderBy('item_index');
    }
}
