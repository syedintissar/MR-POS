<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

/**
 * Finds mysqldump / mysql client executables (MariaDB 11.4 preferred, XAMPP or Laragon as fallbacks)
 * and builds the temporary credentials file so passwords never appear on a command line.
 */
class ToolLocator
{
    public function dump(): string
    {
        return $this->find(config('mrpos.backup.dump_bin'), ['mariadb-dump.exe', 'mysqldump.exe', 'mariadb-dump', 'mysqldump']);
    }

    public function client(): string
    {
        return $this->find(config('mrpos.backup.client_bin'), ['mariadb.exe', 'mysql.exe', 'mariadb', 'mysql']);
    }

    /** @param  list<string>  $names */
    private function find(?string $configured, array $names): string
    {
        if ($configured) {
            if (is_file($configured)) {
                return $configured;
            }
            throw new RuntimeException("Configured database tool not found: {$configured}");
        }

        $dirs = array_merge(
            ['C:\\mariadb-11.4\\bin'],
            glob('C:\\Program Files\\MariaDB *\\bin', GLOB_ONLYDIR) ?: [],
            glob('C:\\laragon\\bin\\mysql\\*\\bin', GLOB_ONLYDIR) ?: [],
            glob('C:\\laragon\\bin\\mariadb\\*\\bin', GLOB_ONLYDIR) ?: [],
            ['C:\\xampp\\mysql\\bin'],
            explode(PATH_SEPARATOR, (string) getenv('PATH')),
        );

        foreach ($dirs as $dir) {
            foreach ($names as $name) {
                $candidate = rtrim($dir, '\\/').DIRECTORY_SEPARATOR.$name;
                if ($dir !== '' && is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException('Could not find the mysqldump/mysql tools. Set BACKUP_MYSQLDUMP_PATH and BACKUP_MYSQL_PATH in .env.');
    }

    /**
     * Environment for child processes. On Windows, programs that use the network (mariadb-dump, mariadb)
     * fail with "Can't create TCP/IP socket (10106)" when SystemRoot is missing, and some web setups
     * (PHP's built-in server, services) start PHP without it. Always pass it explicitly.
     *
     * @return array<string, string>
     */
    public function processEnvironment(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $root = getenv('SystemRoot') ?: getenv('windir') ?: ($_SERVER['SystemRoot'] ?? $_SERVER['windir'] ?? null);
        if (! $root || ! is_dir($root)) {
            $root = is_dir('C:\Windows') ? 'C:\Windows' : null;
        }

        return $root ? ['SystemRoot' => $root, 'windir' => $root] : [];
    }

    /**
     * Write a temporary [client] options file. The caller must delete it (use withCredentials()).
     *
     * @param  array{host:string,port:int|string,username:string,password:string}  $c
     */
    public function credentialsFile(array $c, string $dir): string
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.'cred-'.bin2hex(random_bytes(6)).'.cnf';
        $esc = fn (string $v) => str_replace(['\\', '"'], ['\\\\', '\\"'], $v);

        file_put_contents($path, "[client]\n"
            .'user="'.$esc($c['username'])."\"\n"
            .'password="'.$esc($c['password'])."\"\n"
            .'host="'.$esc((string) $c['host'])."\"\n"
            .'port='.(int) $c['port']."\n");
        @chmod($path, 0600);

        return $path;
    }

    /**
     * @param  array{host:string,port:int|string,username:string,password:string}  $c
     * @param  callable(string $cnfPath): mixed  $callback
     */
    public function withCredentials(array $c, string $dir, callable $callback): mixed
    {
        $path = $this->credentialsFile($c, $dir);

        try {
            return $callback($path);
        } finally {
            @unlink($path);
        }
    }

    /** Credentials of the application's own DB account (used for verify and restore). @return array{host:string,port:int|string,username:string,password:string,database:string} */
    public function appCredentials(): array
    {
        $cfg = config('database.connections.'.config('database.default'));

        return [
            'host' => $cfg['host'],
            'port' => $cfg['port'],
            'username' => $cfg['username'],
            'password' => (string) $cfg['password'],
            'database' => $cfg['database'],
        ];
    }

    /** Credentials of the read-only backup account, falling back to the application account. @return array{host:string,port:int|string,username:string,password:string,database:string} */
    public function dumpCredentials(): array
    {
        $app = $this->appCredentials();
        $user = config('mrpos.backup.dump_username');

        if ($user) {
            $app['username'] = $user;
            $app['password'] = (string) config('mrpos.backup.dump_password');
        }

        return $app;
    }
}
