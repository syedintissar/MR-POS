<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Decimal;
use App\Support\Money;
use Carbon\Carbon;

/**
 * What a report produces: a title and one or more tables of plain values. The same result is shown on screen, printed, made into a PDF
 * and exported to CSV / Excel, so the figures can never differ between the four.
 *
 * Column types: text, date, datetime, int, qty, money, percent. A column can name a permission in `gate`: people without it never receive
 * the column or any of its values (cost and profit columns), whichever way they look at the report.
 */
final class ReportResult
{
    /** @var list<array{title: ?string, columns: list<array{key: string, label: string, type: string, gate: ?string}>, rows: list<array<string, mixed>>, totals: ?array<string, mixed>}> */
    public array $tables = [];

    /** @var list<string> */
    public array $notes = [];

    public function __construct(public string $title, public string $subtitle = '') {}

    /** @return array{key: string, label: string, type: string, gate: ?string} */
    public static function col(string $key, string $label, string $type = 'text', ?string $gate = null): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'gate' => $gate];
    }

    /**
     * @param  list<array{key: string, label: string, type: string, gate: ?string}>  $columns
     * @param  list<array<string, mixed>>  $rows  a row may carry `_href` (a link for the first column on screen; never exported)
     * @param  array<string, mixed>|null  $totals
     */
    public function table(?string $title, array $columns, array $rows, ?array $totals = null): self
    {
        $this->tables[] = ['title' => $title, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals];

        return $this;
    }

    public function note(string $text): self
    {
        $this->notes[] = $text;

        return $this;
    }

    /** A copy without the columns (and values) this person may not see. */
    public function forUser(User $user): self
    {
        $copy = new self($this->title, $this->subtitle);
        $copy->notes = $this->notes;
        foreach ($this->tables as $t) {
            $keep = array_values(array_filter($t['columns'], fn ($c) => $c['gate'] === null || $user->can($c['gate'])));
            if ($keep === []) {
                continue;
            }
            $allowed = array_fill_keys(array_column($keep, 'key'), true) + ['_href' => true];
            $copy->tables[] = [
                'title' => $t['title'],
                'columns' => $keep,
                'rows' => array_map(fn ($r) => array_intersect_key($r, $allowed), $t['rows']),
                'totals' => $t['totals'] === null ? null : array_intersect_key($t['totals'], $allowed),
            ];
        }

        return $copy;
    }

    public function rowCount(): int
    {
        return array_sum(array_map(fn ($t) => count($t['rows']), $this->tables));
    }

    /** How a value looks on screen, on paper and in the PDF. */
    public static function display(string $type, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => Money::format((string) $value),
            'qty' => Decimal::trim((string) $value),
            'int' => number_format((int) $value),
            'percent' => Decimal::trim(Decimal::round((string) $value, 1)).'%',
            'date' => Carbon::parse((string) $value)->format('d M Y'),
            'datetime' => Carbon::parse((string) $value)->format('d M Y, H:i'),
            default => (string) $value,
        };
    }

    /** How a value is written to a CSV / Excel file: real numbers and ISO dates, so the spreadsheet can add them up. */
    public static function raw(string $type, mixed $value): string|int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'money' => (float) Decimal::round((string) $value, 2),
            'qty' => (float) Decimal::round((string) $value, 3),
            'percent' => (float) Decimal::round((string) $value, 1),
            'int' => (int) $value,
            'date' => Carbon::parse((string) $value)->format('Y-m-d'),
            'datetime' => Carbon::parse((string) $value)->format('Y-m-d H:i'),
            default => (string) $value,
        };
    }

    public static function numeric(string $type): bool
    {
        return in_array($type, ['money', 'qty', 'int', 'percent'], true);
    }
}
