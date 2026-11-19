<?php

namespace App\Modules\Reports\Services;

use App\Modules\Core\Services\PdfRenderer;
use App\Modules\Core\Support\Money;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports any ReportEngine result to CSV, XLSX (streamed, low memory) or PDF (Arabic-capable mPDF).
 * Cell values are neutralized against spreadsheet formula injection.
 */
class ReportExporter
{
    public function __construct(private PdfRenderer $pdf) {}

    /** @return list<list<string|int|float|null>> header + rows + totals, formatted for humans */
    public function matrix(array $report, bool $numeric = false): array
    {
        $cols = $report['columns'];
        $out = [array_map(fn ($c) => __($c['label']), array_values($cols))];
        foreach ([...$report['rows'], ...(isset($report['totals']) ? [$report['totals']] : [])] as $row) {
            $line = [];
            foreach ($cols as $key => $col) {
                $line[] = $this->cell($row[$key] ?? null, $col['type'], $numeric, $key === 'label' ? __((string) ($row[$key] ?? '')) : null);
            }
            $out[] = $line;
        }

        return $out;
    }

    private function cell(mixed $v, string $type, bool $numeric, ?string $label): string|int|float|null
    {
        if ($label !== null) {
            return self::safe($label);
        }
        if ($v === null) {
            return '';
        }

        return match ($type) {
            'money' => $numeric ? (float) Money::toDecimal((int) $v) : Money::format((int) $v, false),
            'percent' => $numeric ? round(((int) $v) / 100, 2) : number_format(((int) $v) / 100, 2).'%',
            'int' => (int) $v,
            default => self::safe((string) $v),
        };
    }

    /** Prevent CSV/XLSX formula injection: text starting with = + - @ tab or CR is prefixed. */
    public static function safe(string $v): string
    {
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }

    public function csv(array $report, string $name): StreamedResponse
    {
        $rows = $this->matrix($report);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
            foreach ($rows as $r) {
                fputcsv($out, $r, ',', '"', '\\');
            }
            fclose($out);
        }, $this->filename($name, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function xlsx(array $report, string $name): StreamedResponse
    {
        $rows = $this->matrix($report, numeric: true);

        return response()->streamDownload(function () use ($rows) {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            foreach ($rows as $r) {
                $writer->addRow(Row::fromValues($r));
            }
            $writer->close();
        }, $this->filename($name, 'xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function pdf(array $report, string $name, array $meta): Response
    {
        $html = view('documents.report', ['matrix' => $this->matrix($report), 'title' => __($report['title']), 'meta' => $meta])->render();

        return $this->pdf->download($html, $this->filename($name, 'pdf'));
    }

    private function filename(string $name, string $ext): string
    {
        return Str::slug($name).'-'.now()->format('Ymd-His').'.'.$ext;
    }
}
