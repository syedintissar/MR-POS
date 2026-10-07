<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SystemCheck;
use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'pos:doctor';

    protected $description = 'Check this PC (PHP extensions, database, clock, folders, backup tools). Exit code 1 if anything required is missing.';

    public function handle(SystemCheck $check): int
    {
        $rows = $check->run();

        $this->table(['Check', 'Result', 'Detail'], array_map(fn ($r) => [
            $r['name'],
            ['ok' => 'OK', 'warn' => 'WARN', 'fail' => 'FAIL'][$r['status']],
            $r['detail'],
        ], $rows));

        if (SystemCheck::hasFailures($rows)) {
            $this->error('Some required checks failed. Fix them before using the system.');
            $this->line('Note: this checks the command-line PHP. The web server (Apache) uses its own copy of the settings:');
            $this->line('restart Apache after changing php.ini, then check the dashboard "System checks" card.');

            return self::FAILURE;
        }

        $this->info('All required checks passed.');

        return self::SUCCESS;
    }
}
