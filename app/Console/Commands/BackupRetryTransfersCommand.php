<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\TransferService;
use Illuminate\Console\Command;

class BackupRetryTransfersCommand extends Command
{
    protected $signature = 'pos:backup:retry-transfers';

    protected $description = 'Retry copying backups to secondary / off-site destinations that are still pending.';

    public function handle(TransferService $transfers): int
    {
        $r = $transfers->runDue();
        $this->line("Transfers: {$r['success']} copied, {$r['failed']} still waiting.");

        return self::SUCCESS;
    }
}
