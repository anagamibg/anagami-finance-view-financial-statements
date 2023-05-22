<?php

namespace App\Exports\Databackd\Reports;

use App\Exports\Databackd\Concerns\NeutralizesFormulas;
use App\Services\Databackd\Reports\CashflowStatement;
use App\Services\Databackd\Reports\ReportFilters;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export of the cash flow statement: title and filters on top, then the table with real numbers
 * (number format, frozen label column and header row).
 */
class CashflowExport implements FromArray, WithTitle, WithStyles, WithColumnFormatting, WithColumnWidths
{

    use NeutralizesFormulas;

    protected array $statement;
    protected array $lines = [];
    protected array $meta = ['header' => 1, 'last_row' => 1, 'last_col' => 2, 'bold_rows' => [], 'child_rows' => []];

    public function __construct(protected ReportFilters $filters, protected int $companyId)
    {
        $this->statement = (new CashflowStatement($companyId))->build($filters);
        $this->prepare();
    }

    public function array(): array
    {
        return $this->lines;
    }

    public function title(): string
    {
        return mb_substr(__('databackd-reports-cashflow.sheet_title'), 0, 31);
    }

    public function columnWidths(): array
    {
        $widths = ['A' => 62];

        for ($i = 2; $i <= $this->meta['last_col']; $i++) {
            $widths[Coordinate::stringFromColumnIndex($i)] = 17;
        }

        return $widths;
    }

    public function columnFormats(): array
    {
        $formats = [];

        for ($i = 2; $i <= $this->meta['last_col']; $i++) {
            $formats[Coordinate::stringFromColumnIndex($i)] = '#,##0.00;[Red]-#,##0.00';
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $header = $this->meta['header'];
        $lastCol = Coordinate::stringFromColumnIndex($this->meta['last_col']);

        $sheet->freezePane('B' . ($header + 1));
        $sheet->getStyle('B' . $header . ':' . $lastCol . $header)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A' . $header . ':' . $lastCol . $header)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D82F5']],
        ]);

        foreach ($this->meta['bold_rows'] as $row) {
            $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F4F7']],
            ]);
        }

        foreach ($this->meta['child_rows'] as $row) {
            $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFont()->getColor()->setRGB('616E80');
        }

        // total column
        $sheet->getStyle($lastCol . ($header + 1) . ':' . $lastCol . $this->meta['last_row'])->getFont()->setBold(true);

        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
        ];
    }

    protected function prepare(): void
    {
        $lang = fn(string $key, array $replace = []) => __('databackd-reports-cashflow.' . $key, $replace);
        $columns = $this->statement['columns'];
        $cols = count($columns) + 2;
        $pad = fn(array $row) => array_pad($row, $cols, null);

        $lines = [$pad([$lang('pdf_title')])];

        // S6/P4: the filter summary can include user-controlled dimension/entity names - neutralized here,
        // same as the row labels in line() below.
        foreach ($this->filters->summary() as $line) {
            $lines[] = $pad([self::neutralizeFormula($line)]);
        }

        $lines[] = $pad([]);
        $this->meta['header'] = count($lines) + 1;

        $lines[] = array_merge(
            [$lang('columns.label')],
            array_column($columns, 'label'),
            [$lang('columns.total')]
        );

        $boldKinds = ['opening', 'operating', 'investing', 'financing', 'net', 'closing'];

        foreach ($this->statement['rows'] as $row) {
            $lines[] = $this->line($row['label'], $row);
            $rowNo = count($lines);

            if (in_array($row['kind'], $boldKinds, true)) {
                $this->meta['bold_rows'][] = $rowNo;
            }

            foreach ($row['children'] as $child) {
                $lines[] = $this->line('    ' . ($child['code'] !== '' ? $child['code'] . ' ' : '') . $child['label'], $child);
                $this->meta['child_rows'][] = count($lines);
            }
        }

        $this->meta['last_row'] = count($lines);
        $this->meta['last_col'] = $cols;

        $lines[] = $pad([]);
        $cash = $this->statement['notes']['cash_accounts'] ? implode(', ', $this->statement['notes']['cash_accounts']) : $lang('notes.no_cash_accounts');
        $lines[] = $pad([$lang('notes.basis', ['accounts' => $cash])]);
        $lines[] = $pad([$lang('rows.other') . ': ' . $lang('hints.other')]);

        if ($this->statement['notes']['dimensions_ignored']) {
            $lines[] = $pad([$lang('notes.dimensions')]);
        }

        $this->lines = $lines;
    }

    protected function line(string $label, array $row): array
    {
        $values = [];

        foreach ($this->statement['columns'] as $col) {
            $values[] = (float)($row['values'][$col['key']] ?? 0);
        }

        // S6/P4: $label is (or contains) a user-controlled category/account label - neutralized against
        // CSV/Excel formula injection.
        return array_merge([self::neutralizeFormula($label)], $values, [(float)$row['total']]);
    }

}
