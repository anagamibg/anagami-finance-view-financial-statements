<?php

namespace App\Exports\Databackd\Gaps\Margins;

use App\Exports\Databackd\Concerns\NeutralizesFormulas;
use App\Models\Company;
use App\Services\Databackd\Reports\ReportFilters;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Common part of the sheets of MarginExport: title lines (company, report, filters), header row, data rows, totals row.
 */
abstract class MarginSheet implements FromArray, WithTitle, WithStyles, WithColumnFormatting, ShouldAutoSize
{

    // AFV (S10): user-controlled strings (company / product / group / category names) are neutralized against
    // CSV/Excel formula injection, numbers are left as numbers
    use NeutralizesFormulas;

    const NUMBER_FORMAT = '#,##0.00;[Red]-#,##0.00';
    const QTY_FORMAT = '#,##0.##';
    const PCT_FORMAT = '0.0%;[Red]-0.0%';

    /** @var array<int, array> */
    protected array $lines = [];
    protected int $headerRow = 1;
    protected int $totalRow = 0;
    protected int $lastColumn = 1;
    /** @var array<string, string> column letter => number format */
    protected array $formats = [];

    public function __construct(protected array $report, protected ReportFilters $filters, protected int $companyId)
    {
        $this->prepare();
    }

    abstract protected function sheetTitle(): string;

    /** @return string[] header cells */
    abstract protected function headers(): array;

    /** Fills $this->lines after the header row and sets $this->totalRow / $this->formats. */
    abstract protected function body(): void;

    public function array(): array
    {
        return $this->lines;
    }

    public function title(): string
    {
        return mb_substr($this->sheetTitle(), 0, 31);
    }

    protected function prepare(): void
    {
        $company = Company::find($this->companyId);

        $this->lines[] = [self::neutralizeFormula(($company->company_name ?? '') . ' – ' . __('databackd-gaps-margins.export.pdf_title'))]; // AFV (S10)

        foreach ($this->filters->summary() as $line) {
            if (!str_starts_with($line, __('databackd-reports-common.filters.periodicity'))) {
                $this->lines[] = [self::neutralizeFormula($line)]; // AFV (S10)
            }
        }

        if ($this->report['filters']['category']) {
            $this->lines[] = [self::neutralizeFormula(__('databackd-gaps-margins.filters.category') . ': ' . $this->report['filters']['category'])]; // AFV (S10)
        }

        if ($this->report['compare']) {
            $c = $this->report['compare'];
            $this->lines[] = [__('databackd-gaps-margins.compare_period', ['from' => $c['from']->format('d.m.Y'), 'to' => $c['to']->format('d.m.Y')])];
        }

        $this->lines[] = [__('databackd-gaps-margins.currency_note', ['currency' => $this->report['currency']])];
        $this->lines[] = [];
        $this->headerRow = count($this->lines) + 1;

        $headers = $this->headers();
        $this->lastColumn = count($headers);
        $this->lines[] = self::neutralizeRow($headers); // AFV (S10)

        $this->body();
    }

    public function columnFormats(): array
    {
        return $this->formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($this->lastColumn);
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            $this->headerRow => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2A78D6']],
                'alignment' => ['wrapText' => true],
            ],
        ];

        if ($this->totalRow) {
            $styles['A' . $this->totalRow . ':' . $last . $this->totalRow] = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF3']],
            ];
        }

        $sheet->freezePane('B' . ($this->headerRow + 1));

        return $styles;
    }

    protected function ratio($pct): ?float
    {
        return $pct === null ? null : $pct / 100;
    }

    protected function letter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

}
