<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Day / week (Monday to Sunday) / month / year periods: the SQL that buckets a column, and the list of periods inside a date range. */
final class Periods
{
    public const GROUPS = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    /** The SQL that turns a date column into the first day of its period ('' = everything in one bucket when $group is null). */
    public static function sql(string $column, ?string $group): string
    {
        return match ($group) {
            null => "''",
            'day' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')",
            'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            'year' => "DATE_FORMAT({$column}, '%Y-01-01')",
            default => throw new InvalidArgumentException("Unknown grouping [{$group}]."),
        };
    }

    /**
     * Every period that overlaps the range, empty ones too. The first period starts where the range starts and the last ends where it ends.
     *
     * @return list<array{key: string, from: string, to: string, label: string}> key = first day of the whole period (what sql() produces)
     */
    public static function between(string $from, string $to, string $group): array
    {
        if (! isset(self::GROUPS[$group])) {
            throw new InvalidArgumentException("Unknown grouping [{$group}].");
        }
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        $out = [];
        $cursor = self::startOf($start, $group);
        while ($cursor->lessThanOrEqualTo($end)) {
            $next = self::next($cursor, $group);
            $first = $cursor->lessThan($start) ? $start : $cursor;
            $last = $next->subDay()->greaterThan($end) ? $end : $next->subDay();
            $out[] = ['key' => $cursor->toDateString(), 'from' => $first->toDateString(), 'to' => $last->toDateString(), 'label' => self::label($first, $last, $group)];
            $cursor = $next;
        }

        return $out;
    }

    private static function startOf(CarbonImmutable $d, string $group): CarbonImmutable
    {
        return match ($group) {
            'day' => $d->startOfDay(),
            'week' => $d->startOfWeek(CarbonImmutable::MONDAY)->startOfDay(),
            'month' => $d->startOfMonth(),
            'year' => $d->startOfYear(),
        };
    }

    private static function next(CarbonImmutable $d, string $group): CarbonImmutable
    {
        return match ($group) {
            'day' => $d->addDay(),
            'week' => $d->addWeek(),
            'month' => $d->addMonthNoOverflow(),
            'year' => $d->addYear(),
        };
    }

    private static function label(CarbonImmutable $first, CarbonImmutable $last, string $group): string
    {
        return match ($group) {
            'day' => $first->format('D d M Y'),
            'week' => $first->format('d M').' – '.$last->format('d M Y'),
            'month' => $first->format('F Y'),
            'year' => $first->format('Y'),
        };
    }
}
