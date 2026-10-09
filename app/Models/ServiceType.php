<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of work the shop does (printer repair, toner refill ...), with an optional suggested charge. */
class ServiceType extends Model
{
    protected $fillable = ['name', 'default_charge', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['default_charge' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class);
    }
}
