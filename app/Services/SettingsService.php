<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Support\SettingsCatalogue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Typed access to the settings table. Defaults come from SettingsCatalogue, so a fresh install
 * works without seeding and new settings need no migration.
 */
class SettingsService
{
    private const CACHE_KEY = 'settings.values';

    /** @var array<string, string|null>|null */
    private ?array $memo = null;

    public function __construct(private readonly AuditLogger $audit) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if (! SettingsCatalogue::exists($key)) {
            return $default;
        }

        $def = SettingsCatalogue::definition($key);
        $raw = $this->stored()[$key] ?? null;

        if ($raw === null) {
            return $def['default'];
        }

        return $this->cast($def['type'], $raw);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key, false);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key, '');
    }

    public function int(string $key): int
    {
        return (int) $this->get($key, 0);
    }

    /** @return array<string, mixed> key => typed value for one group; encrypted values are never returned. */
    public function group(string $group): array
    {
        $out = [];
        foreach (SettingsCatalogue::forGroup($group) as $key => $def) {
            $out[$key] = $def['type'] === 'encrypted' ? '' : $this->get($key);
        }

        return $out;
    }

    /**
     * Save several settings at once and write one audit entry listing what changed.
     *
     * @param  array<string, mixed>  $values  key => new value (already validated)
     * @return array<string, array{old: mixed, new: mixed}> the changes that were applied
     */
    public function setMany(array $values, ?User $by = null): array
    {
        $changes = [];

        DB::transaction(function () use ($values, $by, &$changes) {
            foreach ($values as $key => $value) {
                if (! SettingsCatalogue::exists($key)) {
                    continue;
                }

                $def = SettingsCatalogue::definition($key);
                $type = $def['type'];

                // An empty password field means "leave it as it is".
                if ($type === 'encrypted' && ($value === null || $value === '')) {
                    continue;
                }

                $old = $this->get($key);
                $stored = $this->serialize($type, $value);
                $new = $this->cast($type, $stored);

                if ($type !== 'encrypted' && $old === $new) {
                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['group' => $def['group'], 'type' => $type, 'value' => $stored, 'updated_by' => $by?->id],
                );

                $changes[$key] = [
                    'old' => $type === 'encrypted' ? '[hidden]' : $old,
                    'new' => $type === 'encrypted' ? '[changed]' : $new,
                ];
            }

            if ($changes !== []) {
                $this->audit->log(
                    event: 'settings_changed',
                    module: 'settings',
                    description: 'Settings changed: '.implode(', ', array_keys($changes)),
                    old: array_map(fn ($c) => $c['old'], $changes),
                    new: array_map(fn ($c) => $c['new'], $changes),
                    user: $by,
                );
            }
        });

        $this->flush();

        return $changes;
    }

    public function set(string $key, mixed $value, ?User $by = null): void
    {
        $this->setMany([$key => $value], $by);
    }

    public function flush(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, string|null> */
    private function stored(): array
    {
        return $this->memo ??= Cache::remember(
            self::CACHE_KEY,
            3600,
            fn () => Setting::query()->pluck('value', 'key')->all(),
        );
    }

    private function serialize(string $type, mixed $value): ?string
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'int' => (string) (int) $value,
            'decimal' => $value === null || $value === '' ? '0' : (string) $value,
            'encrypted' => Crypt::encryptString((string) $value),
            default => $value === null ? '' : (string) $value,
        };
    }

    private function cast(string $type, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'bool' => (bool) (int) $raw,
            'int' => (int) $raw,
            'decimal' => (string) $raw,
            'encrypted' => $this->decrypt((string) $raw),
            default => (string) $raw,
        };
    }

    private function decrypt(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            // Wrong APP_KEY (e.g. restored onto a new PC without the key): treat as unset instead of crashing.
            return '';
        }
    }
}
