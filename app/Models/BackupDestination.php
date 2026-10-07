<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupDestination extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_active' => 'boolean',
            'requires_encryption' => 'boolean',
            'last_success_at' => 'datetime',
        ];
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(BackupTransfer::class);
    }
}
