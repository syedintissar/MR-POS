<?php

/**
 * Helper for BackupTest: makes a backup in a throw-away PHP process whose environment has NO SystemRoot, the way PHP's
 * built-in server and some services start PHP on Windows. The database connection is opened first (PHP itself needs
 * it), then every case variant of SystemRoot/windir is removed, and only then is the backup made, so the dump and import
 * tools are started without it unless the application passes it explicitly.
 *
 * Prints one line: "OK <filename>" or "ERROR: <message>".
 */

use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Services\Backup\BackupService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.'.config('database.default').'.database') !== 'mrpos_test') {
    echo "ERROR: refusing to run outside the mrpos_test schema\n";
    exit(2);
}

DB::select('select 1');   // connect while the environment is still complete

foreach (array_keys(getenv()) as $name) {
    if (preg_match('/^(systemroot|windir)$/i', $name)) {
        putenv($name);
        unset($_SERVER[$name], $_ENV[$name]);
    }
}

config(['mrpos.backup.root' => $argv[1]]);

try {
    $backup = app(BackupService::class)->create(Backup::TIER_MANUAL);
    echo "OK {$backup->filename}\n";
} catch (BackupException $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
}
