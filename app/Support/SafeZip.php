<?php

declare(strict_types=1);

namespace App\Support;

use ZipArchive;

/**
 * Opens a zip file even when Windows briefly refuses it.
 *
 * Right after a backup file is written, antivirus or the search indexer can hold it for a moment, and ZipArchive::open() then
 * fails although nothing is wrong. The open is retried for a few seconds; only a persistent refusal is returned as a failure.
 */
final class SafeZip
{
    /**
     * @param  callable(int): void|null  $onRetry  called before each new attempt with its number (used by tests to release a lock)
     * @return true|int true when open, otherwise the ZipArchive error code of the last attempt
     */
    public static function open(ZipArchive $zip, string $path, int $flags = 0, int $attempts = 20, int $delayMs = 150, ?callable $onRetry = null): true|int
    {
        $result = $zip->open($path, $flags);

        for ($i = 1; $result !== true && $i < $attempts; $i++) {
            // A file that does not exist (and is not being created) will not appear by waiting.
            if (! ($flags & ZipArchive::CREATE) && ! is_file($path)) {
                break;
            }
            if ($onRetry) {
                $onRetry($i);
            }
            usleep($delayMs * 1000);
            clearstatcache();
            $result = $zip->open($path, $flags);
        }

        return $result;
    }
}
