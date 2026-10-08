<?php

namespace Tests\Feature\Backup;

use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Support\SafeZip;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;
use ZipArchive;

abstract class BackupTestCase extends TestCase
{
    use UsesCommittedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedDatabase();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedDatabase();
        parent::tearDown();
    }

    protected function service(): BackupService
    {
        return app(BackupService::class);
    }

    protected function admin(string $username = 'boss'): User
    {
        return User::factory()->withRole('Admin')->create(['username' => $username, 'password' => 'admin-pass-123']);
    }

    protected function upload(string $relative, string $content): string
    {
        $path = rtrim(config('mrpos.files_path'), '\\/').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);

        return $path;
    }

    protected function zipPath(Backup $b): string
    {
        return $this->service()->absolutePath($b);
    }

    /** @return array<string, string> entry name => contents */
    protected function readZip(string $zipPath): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::RDONLY));
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $out;
    }

    /**
     * Rebuild a backup archive after changing its contents (to simulate damage or a hand-edited backup).
     * The checksum file is deleted so the archive-level checksum does not mask the inner checks being tested.
     *
     * @param  callable(array<string,string>): array<string,string>  $mutate
     */
    protected function rewriteZip(string $zipPath, callable $mutate): void
    {
        $entries = $mutate($this->readZip($zipPath));
        @unlink($zipPath);
        @unlink($zipPath.'.sha256');

        $zip = new ZipArchive;
        $this->assertTrue(SafeZip::open($zip, $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE), 'The archive could not be re-opened for the test.');
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    /** Create a backup row plus a placeholder file, without running a real backup (fast; for retention/transfer tests). */
    protected function fakeBackup(string $tier, \DateTimeInterface $when, array $attrs = []): Backup
    {
        $name = 'mrpos_'.$when->format('Y-m-d_His').'_'.$tier.'_'.bin2hex(random_bytes(2)).'.zip';
        $path = $this->service()->tierPath($tier).DIRECTORY_SEPARATOR.$name;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'placeholder-'.$name);
        file_put_contents($path.'.sha256', hash_file('sha256', $path).'  '.$name.PHP_EOL);

        return Backup::create(array_merge([
            'tier' => $tier, 'status' => Backup::STATUS_SUCCESS, 'filename' => $name, 'disk_path' => $tier.'/'.$name,
            'size_bytes' => filesize($path), 'sha256' => hash_file('sha256', $path),
            'started_at' => $when, 'finished_at' => $when, 'verify_status' => 'passed', 'verify_level' => 'quick', 'verified_at' => $when,
        ], $attrs));
    }
}
