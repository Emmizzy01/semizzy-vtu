<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecurityIncident extends Model
{
    protected $fillable = ['reference', 'title', 'description', 'severity', 'status', 'category', 'reported_by_user_id', 'assigned_to_user_id', 'closed_by_user_id', 'resolution_notes', 'contained_at', 'closed_at'];
    protected function casts(): array { return ['contained_at' => 'datetime', 'closed_at' => 'datetime']; }
    public function events(): HasMany { return $this->hasMany(SecurityIncidentEvent::class, 'incident_id'); }
    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reported_by_user_id'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to_user_id'); }
}
