<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketMessage extends Model
{
    protected $fillable = ['ticket_id', 'author_user_id', 'is_staff', 'body'];
    protected function casts(): array { return ['is_staff' => 'boolean']; }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_user_id'); }
    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
}
