<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Append-only audit trail.
 *
 *  - Rows can never be updated or deleted (database triggers reject both).
 *  - Each row stores a SHA-256 hash chained to the previous row, so any tampering or gap is detectable (pos:audit:verify).
 *  - Each row is also appended to a daily JSON-lines file under storage/logs/audit, which is NOT part of a database restore.
 */
class AuditLogger
{
    private const MASKED_KEY_PATTERN = '/password|passwd|secret|token|remember|authorization|api[_-]?key/i';

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(
        string $event,
        string $module,
        string $description = '',
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?User $user = null,
    ): AuditLog {
        $user ??= auth()->user();
        // Console commands (scheduler, artisan) have no browser request; web requests and HTTP tests do.
        $request = (! app()->runningInConsole() || app()->runningUnitTests()) ? request() : null;

        $row = DB::transaction(function () use ($event, $module, $description, $subject, $old, $new, $user, $request) {
            // Serialise appends so the hash chain stays linear even with several terminals logging at once.
            $prev = DB::table('audit_logs')->orderByDesc('id')->lockForUpdate()->value('hash') ?? str_repeat('0', 64);

            $attributes = [
                'user_id' => $user?->id,
                'event' => $event,
                'module' => $module,
                'auditable_type' => $subject?->getMorphClass(),
                'auditable_id' => $subject?->getKey(),
                'old_values' => $old === null ? null : $this->encode($this->mask($old)),
                'new_values' => $new === null ? null : $this->encode($this->mask($new)),
                'description' => mb_substr($description, 0, 500),
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'url' => $request ? mb_substr($request->fullUrl(), 0, 500) : null,
                'prev_hash' => $prev,
                'created_at' => Carbon::now()->format('Y-m-d H:i:s.u'),
            ];
            $attributes['hash'] = self::computeHash($prev, $attributes);

            $id = DB::table('audit_logs')->insertGetId($attributes);

            return AuditLog::query()->findOrFail($id);
        });

        DB::afterCommit(fn () => $this->mirror($row));

        return $row;
    }

    /** @param  array<string, mixed>  $attributes */
    public static function computeHash(string $prev, array $attributes): string
    {
        $canonical = json_encode([
            $attributes['user_id'] ?? null,
            $attributes['event'] ?? null,
            $attributes['module'] ?? null,
            $attributes['auditable_type'] ?? null,
            $attributes['auditable_id'] ?? null,
            $attributes['old_values'] ?? null,
            $attributes['new_values'] ?? null,
            $attributes['description'] ?? null,
            $attributes['ip_address'] ?? null,
            (string) ($attributes['created_at'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $prev.$canonical);
    }

    /**
     * Walk the whole chain. Returns null when intact, otherwise a description of the first problem.
     */
    public function verifyChain(): ?string
    {
        $prev = str_repeat('0', 64);
        $problem = null;

        DB::table('audit_logs')->orderBy('id')->chunk(1000, function ($rows) use (&$prev, &$problem) {
            foreach ($rows as $row) {
                $attrs = (array) $row;

                if ($attrs['prev_hash'] !== $prev) {
                    $problem = "Chain broken at audit row #{$row->id}: previous hash does not match (a row may have been removed).";

                    return false;
                }

                $calc = self::computeHash($prev, $attrs);
                if (! hash_equals($calc, $attrs['hash'])) {
                    $problem = "Audit row #{$row->id} has been altered (hash mismatch).";

                    return false;
                }

                $prev = $attrs['hash'];
            }

            return true;
        });

        return $problem;
    }

    /** @param  array<string, mixed>  $data */
    private function mask(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::MASKED_KEY_PATTERN, $key)) {
                $out[$key] = '[masked]';
            } elseif (is_array($value)) {
                $out[$key] = $this->mask($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function mirror(AuditLog $row): void
    {
        try {
            $dir = (string) config('mrpos.audit_mirror_path');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $line = json_encode(array_merge($row->getAttributes(), ['mirror' => 1]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            file_put_contents($dir.'/audit-'.Carbon::parse($row->created_at)->format('Y-m-d').'.jsonl', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // The database row is the record of truth. A mirror failure must not break a sale, but it must be visible.
            Log::error('Audit mirror write failed: '.$e->getMessage());
        }
    }
}
