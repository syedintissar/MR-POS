<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Exceptions\BackupException;
use App\Support\SafeRename;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/** Runs mysqldump/mariadb-dump and the mysql client. */
class DatabaseDumper
{
    public function __construct(private readonly ToolLocator $tools) {}

    /**
     * Consistent, non-blocking snapshot of the whole database (InnoDB --single-transaction).
     * Tables only (no CREATE DATABASE), so the file can be imported into any schema name.
     */
    public function dump(string $targetFile, string $workDir): void
    {
        $c = $this->tools->dumpCredentials();

        $result = $this->tools->withCredentials($c, $workDir, fn (string $cnf) => Process::timeout((int) config('mrpos.backup.tool_timeout'))->env($this->tools->processEnvironment())->run([
            $this->tools->dump(),
            '--defaults-extra-file='.$cnf,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--add-drop-table',
            '--result-file='.$targetFile,
            $c['database'],
        ]));

        if (! $result->successful()) {
            throw new BackupException('Database dump failed: '.trim($result->errorOutput() ?: $result->output() ?: 'exit code '.$result->exitCode()));
        }

        if (! is_file($targetFile) || filesize($targetFile) === 0) {
            throw new BackupException('Database dump produced no output.');
        }

        if (! $this->hasCompletionFooter($targetFile)) {
            throw new BackupException('Database dump is incomplete (missing the "Dump completed" footer).');
        }

        $this->stripDefiners($targetFile);
    }

    /** Import an SQL file into $database using the application's DB account. */
    public function import(string $sqlFile, string $database, string $workDir): void
    {
        $c = $this->tools->appCredentials();
        $path = str_replace('\\', '/', $sqlFile);

        $result = $this->tools->withCredentials($c, $workDir, fn (string $cnf) => Process::timeout((int) config('mrpos.backup.tool_timeout'))->env($this->tools->processEnvironment())->run([
            $this->tools->client(),
            '--defaults-extra-file='.$cnf,
            '--default-character-set=utf8mb4',
            '--database='.$database,
            '--execute=source '.$path,
        ]));

        if (! $result->successful()) {
            throw new BackupException('Database import failed: '.trim($result->errorOutput() ?: 'exit code '.$result->exitCode()));
        }
    }

    /** Run one or more SQL statements with the application's account (no database selected). */
    public function execute(string $sql, string $workDir): void
    {
        $c = $this->tools->appCredentials();

        $result = $this->tools->withCredentials($c, $workDir, fn (string $cnf) => Process::timeout(300)->env($this->tools->processEnvironment())->run([
            $this->tools->client(),
            '--defaults-extra-file='.$cnf,
            '--execute='.$sql,
        ]));

        if (! $result->successful()) {
            throw new BackupException('Database command failed: '.trim($result->errorOutput() ?: 'exit code '.$result->exitCode()));
        }
    }

    /** Remove every table in the live database so a restore starts from an empty schema. */
    public function wipeTables(): void
    {
        $db = config('database.connections.'.config('database.default').'.database');
        $tables = DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?', [$db]);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $row) {
                DB::statement('DROP TABLE IF EXISTS `'.str_replace('`', '``', $row->t).'`');
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function hasCompletionFooter(string $file): bool
    {
        $size = filesize($file);
        $h = fopen($file, 'rb');
        fseek($h, max(0, $size - 4096));
        $tail = (string) fread($h, 4096);
        fclose($h);

        return str_contains($tail, 'Dump completed');
    }

    /**
     * Dumps carry DEFINER=`user`@`host` on triggers and routines. Strip it so the import works for whichever
     * account performs the restore (for example on a new PC where the account host differs).
     */
    private function stripDefiners(string $file): void
    {
        $tmp = $file.'.tmp';
        $in = fopen($file, 'rb');
        $out = fopen($tmp, 'wb');

        while (($line = fgets($in)) !== false) {
            if (strlen($line) < 4000 && str_contains($line, 'DEFINER=')) {
                $line = preg_replace('/\s*DEFINER=`[^`]*`@`[^`]*`/', '', $line);
            }
            fwrite($out, $line);
        }

        fclose($in);
        fclose($out);
        SafeRename::move($tmp, $file);   // antivirus may hold the new file for a moment on Windows
    }
}
