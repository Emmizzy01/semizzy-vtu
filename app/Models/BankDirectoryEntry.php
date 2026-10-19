<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankDirectoryEntry extends Model
{
    protected $table = 'bank_directory';

    protected $fillable = [
        'provider', 'bank_code', 'bank_name', 'enabled', 'provider_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'provider_updated_at' => 'datetime',
        ];
    }
}
