<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/** Turns a ReportResult into a CSV file, an Excel file or a PDF. The result has already been cut down to what the person may see. */
class ReportExporter
{
    public const FORMATS = ['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'];

    public function fileName(string $key, ReportFilters $f, string $format): string
    {
        return Str::slug($key).'_'.($f->from === $f->to ? $f->from : $f->from.'_to_'.$f->to).'.'.$format;
    }

    /** @return array{0: string, 1: string} content, mime type */
    public function render(string $format, ReportResult $report, string $shopName, string $html = ''): array
    {
        return match ($format) {
            'csv' => [$this->csv($report), 'text/csv; charset=UTF-8'],
            'xlsx' => [$this->xlsx($report), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pdf' => [$this->pdf($html), 'application/pdf'],
            default => throw new RuntimeException('Unknown export format.'),
        };
    }

    public function csv(ReportResult $report): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");   // so Excel opens it as UTF-8
        fputcsv($h, [$report->title], ',', '"', '');
        fputcsv($h, [$report->subtitle], ',', '"', '');
        foreach ($report->tables as $t) {
            fputcsv($h, [], ',', '"', '');
            if ($t['title']) {
                fputcsv($h, [$t['title']], ',', '"', '');
            }
            fputcsv($h, array_map(fn ($c) => $this->safe($c['label']), $t['columns']), ',', '"', '');
            foreach ($t['rows'] as $row) {
                fputcsv($h, $this->cells($t['columns'], $row), ',', '"', '');
            }
            if ($t['totals'] !== null) {
                fputcsv($h, $this->cells($t['columns'], $t['totals']), ',', '"', '');
            }
        }
        foreach ($report->notes as $n) {
            fputcsv($h, [], ',', '"', '');
            fputcsv($h, [$this->safe($n)], ',', '"', '');
        }
        rewind($h);
        $out = (string) stream_get_contents($h);
        fclose($h);

        return $out;
    }

    public function xlsx(ReportResult $report): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the export.');
        }
        try {
            $writer = new XlsxWriter;
            $writer->openToFile($path);

            $bold = new Style(fontBold: true);
            $head = new Style(fontBold: true, backgroundColor: Color::rgb(226, 232, 240));
            $money = new Style(format: '#,##0.00');
            $qty = new Style(format: '#,##0.###');
            $moneyBold = new Style(fontBold: true, format: '#,##0.00');
            $first = true;

            foreach ($report->tables as $i => $t) {
                $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $first = false;
                $sheet->setName($this->sheetName($t['title'] ?: $report->title, $i));

                $writer->addRow(new Row([new StringCell($report->title.($t['title'] ? ' – '.$t['title'] : ''), $bold)]));
                $writer->addRow(new Row([new StringCell($report->subtitle, null)]));
                $writer->addRow(new Row([]));
                $writer->addRow(new Row(array_map(fn ($c) => new StringCell($this->safe($c['label']), $head), $t['columns'])));
                foreach ($t['rows'] as $row) {
                    $writer->addRow(new Row($this->xlsxCells($t['columns'], $row, $money, $qty)));
                }
                if ($t['totals'] !== null) {
                    $writer->addRow(new Row($this->xlsxCells($t['columns'], $t['totals'], $moneyBold, new Style(fontBold: true, format: '#,##0.###'), true)));
                }
                foreach ($report->notes as $n) {
                    $writer->addRow(new Row([]));
                    $writer->addRow(new Row([new StringCell($n, null)]));
                    break;   // the notes belong to the whole report: once, on the first sheet
                }
            }
            if ($report->tables === []) {
                $writer->getCurrentSheet()->setName('Report');
                $writer->addRow(new Row([new StringCell($report->title, $bold)]));
            }
            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    public function pdf(string $html): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);          // nothing is ever fetched from the internet
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [base_path('storage'), base_path('resources')]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    // ------------------------------------------------------------------------------------------------

    /** @return list<string|int|float|null> */
    private function cells(array $columns, array $row): array
    {
        return array_map(function ($c) use ($row) {
            $v = ReportResult::raw($c['type'], $row[$c['key']] ?? null);

            return is_string($v) ? $this->safe($v) : $v;
        }, $columns);
    }

    /** @return list<Cell> */
    private function xlsxCells(array $columns, array $row, Style $money, Style $qty, bool $bold = false): array
    {
        $cells = [];
        foreach ($columns as $c) {
            $v = ReportResult::raw($c['type'], $row[$c['key']] ?? null);
            $style = match ($c['type']) {
                'money', 'percent' => $money,
                'qty' => $qty,
                default => $bold ? new Style(fontBold: true) : null,
            };
            // numbers are real numbers, text is always text (never a formula, even when it starts with "=")
            $cells[] = is_int($v) || is_float($v) ? new NumericCell($v, $style) : new StringCell($this->safe((string) ($v ?? '')), $style);
        }

        return $cells;
    }

    /** A cell that starts with = + - @ would be run as a formula by a spreadsheet: a leading apostrophe-free space makes it plain text. */
    private function safe(string $text): string
    {
        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? ' '.$text : $text;
    }

    private function sheetName(string $title, int $index): string
    {
        $name = trim(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title) ?? '');
        $name = mb_substr($name === '' ? 'Sheet' : $name, 0, 28);

        return $index > 0 ? mb_substr($name, 0, 25).' '.($index + 1) : $name;
    }
}
