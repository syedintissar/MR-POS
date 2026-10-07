<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only from the application's point of view. Rows are only ever created through AuditLogger.
 * The database also rejects UPDATE and DELETE with triggers.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Audit log rows are append-only.');
        }

        return parent::save($options);
    }

    public function delete(): never
    {
        throw new \LogicException('Audit log rows are append-only.');
    }
}
