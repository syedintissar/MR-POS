<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Backup;
use Illuminate\Console\Command;

class BackupListCommand extends Command
{
    protected $signature = 'pos:backup:list {--all : include pruned and failed backups}';

    protected $description = 'List backups and their verification status.';

    public function handle(): int
    {
        $q = Backup::query()->orderByDesc('started_at')->limit(50);
        if (! $this->option('all')) {
            $q->available();
        }

        $this->table(
            ['ID', 'Tier', 'Status', 'Verify', 'Size MB', 'Started', 'File'],
            $q->get()->map(fn (Backup $b) => [
                $b->id, $b->tier, $b->status.($b->pruned_at ? ' (pruned)' : ''),
                $b->verify_status.($b->verify_level ? " ({$b->verify_level})" : ''),
                number_format($b->size_bytes / 1048576, 1), $b->started_at->format('Y-m-d H:i'), $b->filename,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
