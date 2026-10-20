<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityIncidentEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['incident_id', 'actor_user_id', 'event_type', 'note', 'metadata', 'created_at'];
    protected function casts(): array { return ['metadata' => 'array', 'created_at' => 'datetime']; }
    public function incident(): BelongsTo { return $this->belongsTo(SecurityIncident::class, 'incident_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
