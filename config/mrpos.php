<?php

return [

    'version' => '0.2.0',

    /*
    |--------------------------------------------------------------------------
    | Backup
    |--------------------------------------------------------------------------
    | Paths to the MariaDB/MySQL client tools are auto-detected when left empty.
    | The dump uses the "backup" account (read-only); verify and restore use the application's own DB account.
    */
    'backup' => [
        'root' => env('BACKUP_ROOT', storage_path('app/backups')),
        'dump_bin' => env('BACKUP_MYSQLDUMP_PATH'),
        'client_bin' => env('BACKUP_MYSQL_PATH'),
        'dump_username' => env('BACKUP_DB_USERNAME'),
        'dump_password' => env('BACKUP_DB_PASSWORD'),
        'verify_database' => env('BACKUP_VERIFY_DATABASE', 'mrpos_verify'),
        'tool_timeout' => 3600,
        'heartbeat_file' => storage_path('app/scheduler-heartbeat'),
        'heartbeat_stale_minutes' => 10,
        'deep_verify_every_days' => 7,
        'min_free_bytes' => 2 * 1024 * 1024 * 1024,
        'min_free_percent' => 10,
        // Tables whose row counts change constantly and are therefore exempt from the exact-count check.
        'volatile_tables' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches'],
    ],

    /*
    | Daily JSON-lines copy of every audit entry. It lives outside the database, so a database restore cannot erase it.
    */
    'audit_mirror_path' => storage_path('logs/audit'),

    /*
    | Directory holding uploaded files (product images, receipts, logo). Included in every backup.
    */
    'files_path' => storage_path('app/private'),

];
