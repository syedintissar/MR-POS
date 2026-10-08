<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Moves a file even when Windows briefly refuses.
 *
 * On Windows a file that was written a moment ago can be held open for a short while by antivirus or the search indexer, and
 * rename() then fails with "Access is denied" although nothing is wrong. A backup must not fail for that, so the move is retried
 * for a few seconds, then done as copy-and-delete, and only then reported as a real failure.
 */
final class SafeRename
{
    /**
     * @param  callable(int): void|null  $onRetry  called before each new attempt with its number (used by tests to release a lock)
     */
    public static function move(string $from, string $to, int $attempts = 20, int $delayMs = 150, ?callable $onRetry = null): void
    {
        $lastError = 'the file is in use';
        // The failed attempts are expected and handled here, so PHP's own warnings about them are collected instead of raised.
        set_error_handler(function (int $no, string $message) use (&$lastError): bool {
            $lastError = $message;

            return true;
        });

        try {
            for ($i = 1; $i <= $attempts; $i++) {
                if (rename($from, $to)) {
                    return;
                }
                if ($i < $attempts) {
                    if ($onRetry) {
                        $onRetry($i);
                    }
                    usleep($delayMs * 1000);
                    clearstatcache();
                }
            }

            // last resort: copy, then remove the original (a file that is "in use" can usually still be read)
            if (copy($from, $to) && filesize($to) === filesize($from)) {
                unlink($from);

                return;
            }
        } finally {
            restore_error_handler();
        }

        throw new RuntimeException("Could not move {$from} to {$to}: {$lastError}");
    }
}
