<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditVerifyCommand extends Command
{
    protected $signature = 'pos:audit:verify';

    protected $description = 'Check the audit log hash chain for altered or missing rows. Exit code 1 if tampering is detected.';

    public function handle(AuditLogger $audit): int
    {
        $problem = $audit->verifyChain();

        if ($problem) {
            $this->error('AUDIT LOG PROBLEM: '.$problem);

            return self::FAILURE;
        }

        $this->info('Audit log intact ('.number_format(DB::table('audit_logs')->count()).' entries, hash chain verified).');

        return self::SUCCESS;
    }
}
