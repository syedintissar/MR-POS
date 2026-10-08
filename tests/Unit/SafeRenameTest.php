<?php

namespace Tests\Unit;

use App\Support\SafeRename;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SafeRenameTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saferename-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_it_moves_a_file_and_replaces_an_existing_one(): void
    {
        $from = $this->dir.'/a.tmp';
        $to = $this->dir.'/a.sql';
        file_put_contents($from, 'new');
        file_put_contents($to, 'old');

        SafeRename::move($from, $to);

        $this->assertFileDoesNotExist($from);
        $this->assertSame('new', file_get_contents($to));
    }

    public function test_it_waits_out_a_file_that_is_briefly_held_open(): void
    {
        // On Windows a file somebody still has open cannot be replaced ("Access is denied"); elsewhere the first try simply works.
        $from = $this->dir.'/b.tmp';
        $to = $this->dir.'/b.sql';
        file_put_contents($from, 'new');
        file_put_contents($to, 'old');
        $holder = fopen($to, 'rb');
        $retries = 0;

        SafeRename::move($from, $to, 10, 5, function (int $attempt) use (&$holder, &$retries) {
            $retries++;
            if ($holder) {          // the antivirus lets go after the second look
                fclose($holder);
                $holder = null;
            }
        });
        if ($holder) {
            fclose($holder);
        }

        $this->assertSame('new', file_get_contents($to));
        $this->assertFileDoesNotExist($from);
        $this->assertLessThanOrEqual(1, $retries, 'at most one retry was needed');
    }

    public function test_a_real_failure_is_reported(): void
    {
        $this->expectException(RuntimeException::class);
        SafeRename::move($this->dir.'/does-not-exist.tmp', $this->dir.'/x.sql', 2, 1);
    }
}
