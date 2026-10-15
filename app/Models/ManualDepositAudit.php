<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualDepositAudit extends Model
{
    public $timestamps = false;

    protected $fillable = ['manual_deposit_id', 'actor_user_id', 'event_type', 'outcome', 'reason', 'context', 'created_at'];

    protected function casts(): array { return ['context' => 'array', 'created_at' => 'datetime']; }

    public function deposit(): BelongsTo { return $this->belongsTo(ManualDeposit::class, 'manual_deposit_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
