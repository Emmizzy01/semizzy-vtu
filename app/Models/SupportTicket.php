<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = ['public_id', 'user_id', 'category', 'subject', 'status', 'priority', 'last_reply_at'];
    protected function casts(): array { return ['last_reply_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function messages(): HasMany { return $this->hasMany(SupportTicketMessage::class, 'ticket_id'); }
}
