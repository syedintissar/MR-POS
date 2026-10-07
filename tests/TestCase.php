<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        // Everything a test writes to disk goes into a throw-away folder, never into the real backups or uploads.
        $this->sandbox = storage_path('framework/testing/sandbox-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->sandbox);

        config([
            'mrpos.backup.root' => $this->sandbox.DIRECTORY_SEPARATOR.'backups',
            'mrpos.files_path' => $this->sandbox.DIRECTORY_SEPARATOR.'private',
            'mrpos.backup.heartbeat_file' => $this->sandbox.DIRECTORY_SEPARATOR.'heartbeat',
            'mrpos.audit_mirror_path' => $this->sandbox.DIRECTORY_SEPARATOR.'audit',
        ]);

        // Safety net: these tests must never run against the real shop database.
        $this->assertSame('mrpos_test', config('database.connections.'.config('database.default').'.database'), 'Tests must use the mrpos_test schema.');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }
}
