<?php

namespace App\Exports\Databackd\Reports;

use App\Exports\Databackd\Concerns\NeutralizesFormulas;
use App\Models\Company;
use App\Services\Databackd\Reports\PnlStatement;
use App\Services\Databackd\Reports\ReportFilters;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export of the profit and loss statement: expanded by accounts, real numbers, frozen panes.
 * Columns: account code | item | period columns | total | % of revenue | [previous period | change %].
 */
class PnlExport implements FromArray, WithTitle, WithStyles, WithColumnFormatting, WithColumnWidths
{

    use NeutralizesFormulas;

    const NUMBER_FORMAT = '#,##0.00;[Red]-#,##0.00';
    const PCT_FORMAT = '0.0%;[Red]-0.0%';

    protected array $statement;
    protected array $lines = [];
    /** @var array<int, array{row: int, style: string, type: string}> */
    protected array $rowMeta = [];
    protected int $headerRow = 1;
    protected int $lastColumn = 2;
    protected int $firstDataColumn = 3;
    protected int $totalColumn = 3;
    protected int $titleRows = 0;

    public function __construct(protected ReportFilters $filters, protected int $companyId)
    {
        $this->statement = (new PnlStatement($companyId))->build($filters);
        $this->prepare();
    }

    public function array(): array
    {
        return $this->lines;
    }

    public function title(): string
    {
        return mb_substr(__('databackd-reports-pnl.export.sheet'), 0, 31);
    }

    protected function prepare(): void
    {
        $s = $this->statement;
        $company = Company::find($this->companyId);
        $hasCompare = $s['compare'] !== null;

        // S6/P4: these lines embed the company name and filter summary (both user-controlled) - neutralized
        // against CSV/Excel formula injection, same as the data rows below.
        $this->lines[] = [self::neutralizeFormula(($company->company_name ?? '') . ' – ' . __('databackd-reports-pnl.export.pdf_title'))];

        foreach ($this->filters->summary() as $line) {
            $this->lines[] = [self::neutralizeFormula($line)];
        }

        if ($hasCompare) {
            $this->lines[] = [__('databackd-reports-pnl.compare_period', ['from' => $s['compare']['from']->format('d.m.Y'), 'to' => $s['compare']['to']->format('d.m.Y')])];
        }

        $this->lines[] = [__('databackd-reports-pnl.currency_note', ['currency' => $s['currency']])];
        $this->lines[] = [];
        $this->titleRows = count($this->lines);
        $this->headerRow = $this->titleRows + 1;

        $header = [__('databackd-reports-pnl.columns.code'), __('databackd-reports-pnl.columns.item')];
        foreach ($s['columns'] as $c) {
            $header[] = $c['label'];
        }
        $this->firstDataColumn = 3;
        $this->totalColumn = 3 + count($s['columns']);
        $header[] = __('databackd-reports-pnl.columns.total');
        $header[] = __('databackd-reports-pnl.columns.pct');

        if ($hasCompare) {
            $header[] = __('databackd-reports-pnl.columns.previous');
            $header[] = __('databackd-reports-pnl.columns.change_abs');
            $header[] = __('databackd-reports-pnl.columns.change');
        }

        $this->lastColumn = count($header);
        $this->lines[] = self::neutralizeRow($header);

        foreach ($s['rows'] as $row) {
            // S6/P4: account code/name are user-controlled (fin_accounts) - neutralized here.
            $line = self::neutralizeRow([$row['code'], ($row['level'] === 2 ? '    ' : '') . $row['name']]);

            if ($row['type'] !== 'section') {
                foreach ($s['columns'] as $c) {
                    $line[] = (float)($row['values'][$c['key']] ?? 0);
                }

                $line[] = (float)$row['total'];
                $line[] = $row['pct'] !== null ? $row['pct'] / 100 : null;

                if ($hasCompare) {
                    $line[] = (float)$row['prev'];
                    $line[] = (float)$row['change'];
                    $line[] = $row['change_pct'] !== null ? $row['change_pct'] / 100 : null;
                }
            }

            $this->lines[] = $line;
            $this->rowMeta[] = ['row' => count($this->lines), 'style' => $row['style'], 'type' => $row['type']];
        }

        $this->lines[] = [];
        $this->lines[] = [__('databackd-reports-pnl.notes.pct_base')];
        $this->lines[] = [$s['tax']['mode'] === 'actual'
            ? __('databackd-reports-pnl.notes.tax_actual')
            : __('databackd-reports-pnl.notes.tax_estimated', ['rate' => (int)round($s['tax']['rate'] * 100)])];

        if ($s['dimension_note']) {
            $this->lines[] = [__('databackd-reports-pnl.notes.dimension_' . $s['dimension_note'])];
        }
    }

    public function columnWidths(): array
    {
        $widths = ['A' => 11, 'B' => 46];

        for ($i = 3; $i <= $this->lastColumn; $i++) {
            $widths[Coordinate::stringFromColumnIndex($i)] = $i === $this->totalColumn + 1 || $i === $this->totalColumn + 4 ? 12 : 16;
        }

        return $widths;
    }

    public function columnFormats(): array
    {
        $s = $this->statement;
        $formats = [];

        for ($i = $this->firstDataColumn; $i <= $this->totalColumn; $i++) {
            $formats[Coordinate::stringFromColumnIndex($i)] = self::NUMBER_FORMAT;
        }

        $formats[Coordinate::stringFromColumnIndex($this->totalColumn + 1)] = self::PCT_FORMAT;

        if ($s['compare'] !== null) {
            $formats[Coordinate::stringFromColumnIndex($this->totalColumn + 2)] = self::NUMBER_FORMAT;
            $formats[Coordinate::stringFromColumnIndex($this->totalColumn + 3)] = self::NUMBER_FORMAT;
            $formats[Coordinate::stringFromColumnIndex($this->totalColumn + 4)] = self::PCT_FORMAT;
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $last = Coordinate::stringFromColumnIndex($this->lastColumn);
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            $this->headerRow => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D82F5']],
                'alignment' => ['wrapText' => true],
            ],
        ];

        foreach ($this->rowMeta as $meta) {
            $range = 'A' . $meta['row'] . ':' . $last . $meta['row'];

            if ($meta['type'] === 'section') {
                $styles[$range] = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF3']]];
            } elseif ($meta['style'] === 'result') {
                $styles[$range] = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBFB']]];
            } elseif ($meta['style'] === 'subtotal') {
                $styles[$range] = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F4F7']]];
            } elseif ($meta['type'] === 'account') {
                $styles[$range] = ['font' => ['color' => ['rgb' => '595959']]];
            }
        }

        // freeze the header and the code/item columns
        $sheet->freezePane('C' . ($this->headerRow + 1));
        return $styles;
    }

}
