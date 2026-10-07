<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Backup\ToolLocator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Health checks for the server PC: PHP, database, clock, folders and backup tools.
 * Used by `php artisan pos:doctor` (command line PHP) and the dashboard (the web server's PHP,
 * which can differ: Apache must be restarted after php.ini changes).
 */
class SystemCheck
{
    public const REQUIRED_EXTENSIONS = ['intl', 'zip', 'gd', 'mbstring', 'bcmath', 'openssl', 'fileinfo', 'pdo_mysql', 'curl'];

    public const RECOMMENDED_EXTENSIONS = ['sodium'];

    public function __construct(private readonly ToolLocator $tools) {}

    /**
     * @return list<array{name:string,status:string,detail:string}> status: ok | warn | fail
     */
    public function run(): array
    {
        $checks = [];
        $add = function (string $name, string $status, string $detail) use (&$checks) {
            $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
        };

        $add('PHP version', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'fail', PHP_VERSION.' ('.PHP_SAPI.')');

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $add("PHP extension: {$ext}", extension_loaded($ext) ? 'ok' : 'fail', extension_loaded($ext) ? 'loaded' : 'NOT loaded: enable it in php.ini and restart Apache');
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $ext) {
            $add("PHP extension: {$ext}", extension_loaded($ext) ? 'ok' : 'warn', extension_loaded($ext) ? 'loaded' : 'not loaded (recommended)');
        }

        try {
            $row = DB::selectOne('SELECT VERSION() AS v, NOW() AS n, @@time_zone AS tz');
            $isMaria = stripos($row->v, 'mariadb') !== false;
            $major = (float) $row->v;
            $add('Database server', ($isMaria && $major >= 11.4) ? 'ok' : (($isMaria && $major >= 10.11) || (! $isMaria && $major >= 8.0) ? 'warn' : 'fail'), $row->v.($isMaria && $major >= 11.4 ? '' : ' (MariaDB 11.4 LTS or newer is recommended)'));

            $drift = abs(strtotime((string) $row->n) - strtotime(now()->toDateTimeString()));
            $add('Clock agreement (PHP vs database)', $drift <= 2 ? 'ok' : 'fail', $drift <= 2 ? 'PHP and database agree ('.config('app.timezone').')' : "differ by {$drift} seconds: check APP_TIMEZONE and DB_TIMEZONE");
        } catch (Throwable $e) {
            $add('Database server', 'fail', 'cannot connect: '.$e->getMessage());
        }

        $add('Time zone', config('app.timezone') === 'Asia/Karachi' ? 'ok' : 'warn', (string) config('app.timezone'));
        $add('Application key', config('app.key') ? 'ok' : 'fail', config('app.key') ? 'set' : 'missing: run php artisan key:generate');

        if (app()->environment('production')) {
            $add('Debug mode', config('app.debug') ? 'fail' : 'ok', config('app.debug') ? 'APP_DEBUG is on: turn it off in production' : 'off');
        }

        foreach ([['storage', storage_path()], ['backup folder', (string) config('mrpos.backup.root')], ['uploaded files', (string) config('mrpos.files_path')]] as [$label, $path]) {
            $probe = is_dir($path) ? $path : dirname($path);
            $add("Writable: {$label}", is_dir($probe) && is_writable($probe) ? 'ok' : 'fail', $path);
        }

        foreach (['dump' => 'Backup tool (mysqldump)', 'client' => 'Backup tool (mysql client)'] as $method => $label) {
            try {
                $add($label, 'ok', $this->tools->{$method}());
            } catch (Throwable $e) {
                $add($label, 'fail', $e->getMessage());
            }
        }

        return $checks;
    }

    /** @param  list<array{status:string}>  $checks */
    public static function hasFailures(array $checks): bool
    {
        return collect($checks)->contains('status', 'fail');
    }
}
