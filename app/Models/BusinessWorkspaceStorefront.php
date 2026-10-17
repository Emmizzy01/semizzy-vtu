<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessWorkspaceStorefront extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_workspace_id',
        'slug',
        'display_name',
        'tagline',
        'description',
        'status',
        'enabled_services',
        'accent_color',
    ];

    protected function casts(): array
    {
        return ['enabled_services' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(BusinessWorkspace::class, 'business_workspace_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
