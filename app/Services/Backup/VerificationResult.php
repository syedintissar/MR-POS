<?php

declare(strict_types=1);

namespace App\Services\Backup;

final class VerificationResult
{
    /**
     * @param  list<string>  $notes  what was checked (when passed) or what went wrong (when failed)
     * @param  array<string, mixed>|null  $manifest
     */
    public function __construct(
        public readonly bool $passed,
        public readonly string $level,
        public readonly array $notes,
        public readonly ?array $manifest = null,
    ) {}
}
