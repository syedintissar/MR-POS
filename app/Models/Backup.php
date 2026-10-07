<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Backup extends Model
{
    public const TIER_DAILY = 'daily';

    public const TIER_WEEKLY = 'weekly';

    public const TIER_MONTHLY = 'monthly';

    public const TIER_MANUAL = 'manual';

    public const TIER_SAFETY = 'safety';

    public const TIER_UPLOADED = 'uploaded';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
            'includes_files' => 'boolean',
            'is_protected' => 'boolean',
            'row_counts' => 'array',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
            'pruned_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(BackupTransfer::class);
    }

    /** Successful and its file has not been removed by retention. */
    public function scopeAvailable($query)
    {
        return $query->where('status', self::STATUS_SUCCESS)->whereNull('pruned_at');
    }
}
