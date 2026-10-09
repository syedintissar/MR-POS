<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a job: a part taken from stock, or a charge (labour, refill). Lines are never deleted, only marked removed. */
class ServiceJobItem extends Model
{
    public const PART = 'part';

    public const CHARGE = 'charge';

    public const ACTIVE = 'active';

    public const REMOVED = 'removed';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'unit_cost' => 'decimal:4', 'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2', 'line_total' => 'decimal:2', 'added_at' => 'datetime', 'removed_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function isPart(): bool
    {
        return $this->line_type === self::PART;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
