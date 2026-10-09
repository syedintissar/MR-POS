<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** What the person asked a report for: a period, a grouping, and (for some reports) a customer, supplier, category, cashier ... */
final class ReportFilters
{
    public const PRESETS = [
        'today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'last_week' => 'Last week', 'last_30' => 'Last 30 days',
        'this_month' => 'This month', 'last_month' => 'Last month', 'this_year' => 'This year', 'last_year' => 'Last year', 'custom' => 'Choose dates',
    ];

    /** The optional filters a report may use, and the request key of each. */
    public const EXTRA = ['customer_id', 'supplier_id', 'category_id', 'user_id', 'technician_id', 'method_id', 'status', 'product_id'];

    /** @param  array<string, string|int|null>  $extra */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $group = 'day',
        public readonly string $preset = 'custom',
        public readonly array $extra = [],
    ) {
        if ($from > $to) {
            throw new InvalidArgumentException('The "from" date is after the "to" date.');
        }
        if (! isset(Periods::GROUPS[$group])) {
            throw new InvalidArgumentException('Unknown grouping.');
        }
    }

    /** @param  array<string, mixed>  $input  request values: preset, from, to, group and the extra keys */
    public static function make(array $input, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::today();
        $preset = (string) ($input['preset'] ?? 'this_month');
        if (! isset(self::PRESETS[$preset])) {
            $preset = 'this_month';
        }
        if ($preset === 'custom') {
            $from = ! empty($input['from']) ? CarbonImmutable::parse((string) $input['from'])->toDateString() : $today->startOfMonth()->toDateString();
            $to = ! empty($input['to']) ? CarbonImmutable::parse((string) $input['to'])->toDateString() : $today->toDateString();
        } else {
            [$from, $to] = self::resolve($preset, $today);
        }

        $extra = [];
        foreach (self::EXTRA as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $extra[$key] = is_numeric($input[$key]) && $key !== 'status' ? (int) $input[$key] : (string) $input[$key];
            }
        }

        return new self($from, $to, (string) ($input['group'] ?? 'day'), $preset, $extra);
    }

    /** @return array{0: string, 1: string} */
    public static function resolve(string $preset, CarbonImmutable $today): array
    {
        return match ($preset) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [$today->subDay()->toDateString(), $today->subDay()->toDateString()],
            'this_week' => [$today->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $today->toDateString()],
            'last_week' => [$today->subWeek()->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $today->subWeek()->endOfWeek(CarbonImmutable::SUNDAY)->toDateString()],
            'last_30' => [$today->subDays(29)->toDateString(), $today->toDateString()],
            'this_month' => [$today->startOfMonth()->toDateString(), $today->toDateString()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'this_year' => [$today->startOfYear()->toDateString(), $today->toDateString()],
            'last_year' => [$today->subYear()->startOfYear()->toDateString(), $today->subYear()->endOfYear()->toDateString()],
            default => [$today->startOfMonth()->toDateString(), $today->toDateString()],
        };
    }

    /** First moment of the period, for a date-time column: `col >= start()`. */
    public function start(): string
    {
        return $this->from.' 00:00:00';
    }

    /** First moment after the period, for a date-time column: `col < endExclusive()`. */
    public function endExclusive(): string
    {
        return CarbonImmutable::parse($this->to)->addDay()->toDateString().' 00:00:00';
    }

    public function id(string $key): ?int
    {
        return isset($this->extra[$key]) ? (int) $this->extra[$key] : null;
    }

    public function str(string $key): ?string
    {
        return isset($this->extra[$key]) ? (string) $this->extra[$key] : null;
    }

    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /** "01 Oct 2026" or "01 Oct 2026 to 09 Oct 2026" */
    public function label(): string
    {
        $a = CarbonImmutable::parse($this->from)->format('d M Y');
        $b = CarbonImmutable::parse($this->to)->format('d M Y');

        return $a === $b ? $a : "{$a} to {$b}";
    }

    /** @return array<string, string|int> the query string that reproduces these filters */
    public function query(): array
    {
        return ['preset' => 'custom', 'from' => $this->from, 'to' => $this->to, 'group' => $this->group] + $this->extra;
    }
}
