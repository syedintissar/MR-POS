<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\BackupVerifier;
use Illuminate\Console\Command;

class BackupVerifyCommand extends Command
{
    protected $signature = 'pos:backup:verify {--id= : backup id} {--latest : the newest successful backup} {--file= : a backup zip anywhere on disk} {--deep : also import into a scratch database} {--password= : backup password if the file is encrypted}';

    protected $description = 'Verify the integrity of a backup (quick) or prove it restores (deep). Exit code 1 if verification fails.';

    public function handle(BackupVerifier $verifier): int
    {
        $deep = (bool) $this->option('deep');
        $password = $this->option('password') ?: null;

        if ($file = $this->option('file')) {
            $r = $deep ? $verifier->deepFile($file, null, $password) : $verifier->quickFile($file, null, $password);

            return $this->report($r->passed, $r->notes, $file);
        }

        $backup = $this->option('id')
            ? Backup::query()->find($this->option('id'))
            : Backup::available()->orderByDesc('started_at')->first();

        if (! $backup) {
            $this->error('No backup found to verify.');

            return self::FAILURE;
        }

        $r = $deep ? $verifier->deepAndRecord($backup, $password) : $verifier->quickAndRecord($backup, $password);

        return $this->report($r->passed, $r->notes, (string) $backup->filename);
    }

    /** @param  list<string>  $notes */
    private function report(bool $passed, array $notes, string $name): int
    {
        foreach ($notes as $n) {
            $this->line('  '.$n);
        }
        $passed ? $this->info("Verification PASSED: {$name}") : $this->error("Verification FAILED: {$name}");

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
