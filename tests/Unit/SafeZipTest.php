<?php

namespace Tests\Unit;

use App\Support\SafeZip;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class SafeZipTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'safezip-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function makeZip(string $name, string $content): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.$name;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('a.txt', $content);
        $zip->close();

        return $path;
    }

    public function test_it_opens_a_zip_for_reading(): void
    {
        $path = $this->makeZip('r.zip', 'hello');
        $zip = new ZipArchive;

        $this->assertTrue(SafeZip::open($zip, $path, ZipArchive::RDONLY));
        $this->assertSame('hello', $zip->getFromName('a.txt'));
        $zip->close();
    }

    public function test_it_can_create_and_overwrite_a_zip(): void
    {
        $path = $this->makeZip('w.zip', 'old');
        $zip = new ZipArchive;

        $this->assertTrue(SafeZip::open($zip, $path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('b.txt', 'new');
        $zip->close();

        $check = new ZipArchive;
        $check->open($path, ZipArchive::RDONLY);
        $this->assertSame(1, $check->numFiles);
        $this->assertSame('new', $check->getFromName('b.txt'));
        $check->close();
    }

    public function test_it_waits_out_a_file_that_is_briefly_held_open(): void
    {
        // On Windows a file somebody still has open cannot be overwritten; elsewhere the first try simply works.
        $path = $this->makeZip('h.zip', 'old');
        $holder = fopen($path, 'rb');
        $retries = 0;
        $zip = new ZipArchive;

        $result = SafeZip::open($zip, $path, ZipArchive::CREATE | ZipArchive::OVERWRITE, 10, 5, function () use (&$holder, &$retries) {
            $retries++;
            if ($holder) {
                fclose($holder);
                $holder = null;
            }
        });
        if ($holder) {
            fclose($holder);
        }

        $this->assertTrue($result);
        $this->assertLessThanOrEqual(1, $retries);
        $zip->close();
    }

    public function test_a_missing_file_fails_at_once_instead_of_waiting(): void
    {
        $started = microtime(true);

        $result = SafeZip::open(new ZipArchive, $this->dir.'/missing.zip', ZipArchive::RDONLY, 20, 200);

        $this->assertNotTrue($result);
        $this->assertLessThan(1.0, microtime(true) - $started, 'a file that is not there is not waited for');
    }
}
