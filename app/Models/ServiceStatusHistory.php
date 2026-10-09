<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step in a job's life: who moved it from where to where, when, and why. Append-only (the database refuses edits). */
class ServiceStatusHistory extends Model
{
    protected $table = 'service_status_history';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
