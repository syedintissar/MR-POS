<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Services\SettingsService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Quick: checksums, archive structure, manifest, inner file hashes, SQL header/footer.
 * Deep:  quick + import into a scratch database and compare tables and row counts with the manifest.
 */
class BackupVerifier
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly DatabaseDumper $dumper,
        private readonly ToolLocator $tools,
    ) {}

    public function quickAndRecord(Backup $backup, ?string $password = null): VerificationResult
    {
        $result = $this->quickFile($this->path($backup), $backup->sha256, $password);
        $this->record($backup, $result);

        return $result;
    }

    public function deepAndRecord(Backup $backup, ?string $password = null): VerificationResult
    {
        $result = $this->deepFile($this->path($backup), $backup->sha256, $password);
        $this->record($backup, $result);

        return $result;
    }

    private function record(Backup $backup, VerificationResult $r): void
    {
        $backup->update([
            'verify_status' => $r->passed ? 'passed' : 'failed',
            'verify_level' => $r->level,
            'verified_at' => now(),
            'verify_notes' => implode("\n", $r->notes),
        ]);
    }

    private function path(Backup $backup): string
    {
        return rtrim((string) config('mrpos.backup.root'), '\\/').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $backup->disk_path);
    }

    public function quickFile(string $zipPath, ?string $expectedSha = null, ?string $password = null): VerificationResult
    {
        $notes = [];
        $fail = function (string $why) use (&$notes): VerificationResult {
            return new VerificationResult(false, 'quick', [...$notes, 'FAILED: '.$why]);
        };

        if (! is_file($zipPath)) {
            return $fail('backup file is missing: '.basename($zipPath));
        }

        $sha = hash_file('sha256', $zipPath);
        if ($expectedSha !== null && ! hash_equals($expectedSha, $sha)) {
            return $fail('checksum does not match the recorded value (file changed or corrupted)');
        }
        $notes[] = 'Checksum OK';

        $sidecar = $zipPath.'.sha256';
        if (is_file($sidecar)) {
            $recorded = strtok((string) file_get_contents($sidecar), ' ');
            if (! hash_equals((string) $recorded, $sha)) {
                return $fail('checksum does not match the .sha256 file next to the backup');
            }
            $notes[] = 'Checksum file OK';
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return $fail('archive cannot be opened (corrupted)');
        }

        try {
            $password ??= $this->settings->string('backup.encryption_password') ?: null;
            if ($password) {
                $zip->setPassword($password);
            }

            $raw = $zip->getFromName('manifest.json');
            if ($raw === false) {
                return $fail($password ? 'manifest.json cannot be read (wrong backup password?)' : 'manifest.json is missing or the archive is encrypted and no password was given');
            }

            $manifest = json_decode($raw, true);
            if (! is_array($manifest) || ($manifest['app'] ?? null) !== 'MR-POS' || ! isset($manifest['database_sql_sha256'])) {
                return $fail('manifest.json is not valid');
            }
            $notes[] = 'Manifest OK';

            // database.sql: stream once, hash it, keep header and tail for the sanity check.
            $sql = $this->hashEntry($zip, 'database.sql');
            if ($sql === null) {
                return $fail('database.sql cannot be read from the archive');
            }
            if (! hash_equals($manifest['database_sql_sha256'], $sql['sha'])) {
                return $fail('database.sql does not match its recorded checksum');
            }
            if (! str_contains($sql['head'], 'dump') || ! str_contains($sql['tail'], 'Dump completed')) {
                return $fail('database.sql is incomplete (missing dump header or footer)');
            }
            $notes[] = 'Database dump intact ('.number_format($sql['bytes']).' bytes)';

            foreach ($manifest['files'] ?? [] as $rel => $expected) {
                $h = $this->hashEntry($zip, 'files/'.$rel);
                if ($h === null || ! hash_equals($expected, $h['sha'])) {
                    return $fail("uploaded file {$rel} is missing or damaged");
                }
            }
            $notes[] = count($manifest['files'] ?? []).' uploaded file(s) intact';

            $expected = 2 + count($manifest['files'] ?? []);
            if ($zip->numFiles !== $expected) {
                return $fail("archive contains {$zip->numFiles} entries, expected {$expected}");
            }
        } finally {
            $zip->close();
        }

        return new VerificationResult(true, 'quick', $notes, $manifest);
    }

    public function deepFile(string $zipPath, ?string $expectedSha = null, ?string $password = null): VerificationResult
    {
        $quick = $this->quickFile($zipPath, $expectedSha, $password);
        if (! $quick->passed) {
            return new VerificationResult(false, 'deep', $quick->notes);
        }

        $notes = $quick->notes;
        $manifest = $quick->manifest;
        $scratch = (string) config('mrpos.backup.verify_database');
        $live = (string) config('database.connections.'.config('database.default').'.database');

        if ($scratch === '' || $scratch === $live) {
            return new VerificationResult(false, 'deep', [...$notes, 'FAILED: the verification database must be different from the live database']);
        }

        $tmp = rtrim((string) config('mrpos.backup.root'), '\\/').DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'verify-'.Str::uuid();
        File::ensureDirectoryExists($tmp);

        try {
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::RDONLY);
            $password ??= $this->settings->string('backup.encryption_password') ?: null;
            if ($password) {
                $zip->setPassword($password);
            }
            $zip->extractTo($tmp, ['database.sql']);
            $zip->close();

            $q = '`'.str_replace('`', '', $scratch).'`';
            $this->dumper->execute("DROP DATABASE IF EXISTS {$q}; CREATE DATABASE {$q} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", $tmp);
            $this->dumper->import($tmp.DIRECTORY_SEPARATOR.'database.sql', $scratch, $tmp);

            $conn = $this->scratchConnection($scratch);
            $tables = collect($conn->select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = "BASE TABLE"', [$scratch]))->pluck('t')->all();

            $expectedTables = array_keys($manifest['row_counts_after'] ?? []);
            $missing = array_diff($expectedTables, $tables);
            $extra = array_diff($tables, $expectedTables);
            if ($missing || $extra) {
                return new VerificationResult(false, 'deep', [...$notes, 'FAILED: table list differs from the manifest (missing: '.implode(', ', $missing).'; unexpected: '.implode(', ', $extra).')']);
            }
            $notes[] = count($tables).' tables restored into the scratch database';

            $volatile = (array) config('mrpos.backup.volatile_tables');
            $problems = [];
            foreach ($tables as $t) {
                if (in_array($t, $volatile, true)) {
                    continue;
                }
                $n = (int) $conn->table($t)->count();
                $a = (int) ($manifest['row_counts_before'][$t] ?? 0);
                $b = (int) ($manifest['row_counts_after'][$t] ?? 0);
                if ($n < min($a, $b) || $n > max($a, $b)) {
                    $problems[] = "{$t}: {$n} rows in backup, expected {$a}–{$b}";
                }
            }
            if ($problems) {
                return new VerificationResult(false, 'deep', [...$notes, 'FAILED: row counts differ - '.implode('; ', $problems)]);
            }
            $notes[] = 'Row counts match the manifest';

            return new VerificationResult(true, 'deep', $notes, $manifest);
        } catch (Throwable $e) {
            return new VerificationResult(false, 'deep', [...$notes, 'FAILED: '.$e->getMessage()]);
        } finally {
            try {
                $q = '`'.str_replace('`', '', $scratch).'`';
                $this->dumper->execute("DROP DATABASE IF EXISTS {$q}", $tmp);
            } catch (Throwable) {
                // best effort; the next verify drops it again
            }
            DB::purge('backup_verify');
            File::deleteDirectory($tmp);
        }
    }

    private function scratchConnection(string $database): Connection
    {
        $base = config('database.connections.'.config('database.default'));
        config(['database.connections.backup_verify' => array_merge($base, ['database' => $database])]);
        DB::purge('backup_verify');

        return DB::connection('backup_verify');
    }

    /** @return array{sha:string,head:string,tail:string,bytes:int}|null */
    private function hashEntry(ZipArchive $zip, string $name): ?array
    {
        $stream = $zip->getStream($name);
        if (! $stream) {
            return null;
        }

        $ctx = hash_init('sha256');
        $head = '';
        $tail = '';
        $bytes = 0;

        while (! feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false) {
                fclose($stream);

                return null;
            }
            if ($head === '') {
                $head = substr($chunk, 0, 4096);
            }
            hash_update($ctx, $chunk);
            $bytes += strlen($chunk);
            $tail = substr($tail.$chunk, -4096);
        }
        fclose($stream);

        return ['sha' => hash_final($ctx), 'head' => $head, 'tail' => $tail, 'bytes' => $bytes];
    }
}
