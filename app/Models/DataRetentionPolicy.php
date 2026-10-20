<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataRetentionPolicy extends Model
{
    protected $fillable = ['record_category', 'retention_days', 'enabled', 'requires_legal_review', 'notes', 'updated_by_user_id'];
    protected function casts(): array { return ['enabled' => 'boolean', 'requires_legal_review' => 'boolean']; }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by_user_id'); }
}
