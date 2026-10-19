<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VtuBulkPurchaseItem extends Model
{
    protected $fillable = [
        'batch_id', 'item_index', 'idempotency_key', 'product_code', 'service_type',
        'recipient', 'amount_minor', 'service_details', 'status', 'order_public_id',
        'result_message', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'item_index' => 'integer',
            'amount_minor' => 'integer',
            'service_details' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(VtuBulkPurchaseBatch::class, 'batch_id');
    }
}
