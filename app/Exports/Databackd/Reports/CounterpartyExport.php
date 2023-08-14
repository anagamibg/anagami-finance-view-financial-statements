<?php

namespace App\Exports\Databackd\Reports;

use App\Exports\Databackd\Concerns\NeutralizesFormulas;
use App\Services\Databackd\Reports\ReportFilters;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export of the counterparties report: sheet 1 = the list (turnover, documents, share), sheet 2 = counterparty x month.
 * Numbers are real numbers with a number format, the header row is frozen.
 */
class CounterpartyExport implements WithMultipleSheets
{

    public function __construct(protected ReportFilters $filters, protected int $companyId, protected string $side = 'revenue')
    {
    }

    public function sheets(): array
    {
        $data = new CounterpartyData($this->companyId);
        $listing = $data->listing($this->filters, $this->side);

        return [
            new CounterpartyListSheet($listing),
            new CounterpartyMonthsSheet($data->monthlyMatrix($this->filters, $this->side, $listing)),
        ];
    }

}

class CounterpartyListSheet implements FromArray, WithHeadings, WithColumnFormatting, WithStyles, WithTitle, ShouldAutoSize
{

    use NeutralizesFormulas;

    public function __construct(protected array $listing)
    {
    }

    protected function hasCompare(): bool
    {
        return !empty($this->listing['compare']);
    }

    protected function isAll(): bool
    {
        return $this->listing['side'] === 'all';
    }

    public function title(): string
    {
        return mb_substr(__('databackd-reports-counterparties.menu'), 0, 31);
    }

    public function headings(): array
    {
        $c = fn(string $k) => __('databackd-reports-counterparties.columns.' . $k);
        $h = [$c('counterparty'), $c('turnover')];

        if ($this->isAll()) {
            array_push($h, $c('revenue'), $c('expense'));
        }

        array_push($h, $c('documents'), $c('avg_document'), $c('share'));

        if ($this->hasCompare()) {
            array_push($h, $c('previous'), $c('change'));
        }

        return $h;
    }

    public function array(): array
    {
        $out = [];

        foreach ($this->listing['rows'] as $r) {
            $out[] = $this->line($r['label'], $r);
        }

        $out[] = $this->line(__('databackd-reports-common.total'), $this->listing['totals']);

        return $out;
    }

    protected function line(string $label, array $r): array
    {
        // S6/P4: $label is the counterparty name (user/import-controlled) - neutralized against CSV/Excel
        // formula injection.
        $line = [self::neutralizeFormula($label), (float)$r['total']];

        if ($this->isAll()) {
            array_push($line, (float)$r['revenue'], (float)$r['expense']);
        }

        array_push($line, (int)$r['docs'], (float)$r['avg'], (float)$r['share'] / 100);

        if ($this->hasCompare()) {
            array_push($line, (float)($r['previous'] ?? 0), isset($r['change_pct']) ? (float)$r['change_pct'] / 100 : null);
        }

        return $line;
    }

    public function columnFormats(): array
    {
        $formats = ['B' => '#,##0.00'];
        $col = 3;

        if ($this->isAll()) {
            $formats['C'] = '#,##0.00';
            $formats['D'] = '#,##0.00';
            $col = 5;
        }

        $formats[Coordinate::stringFromColumnIndex($col)] = '#,##0';
        $formats[Coordinate::stringFromColumnIndex($col + 1)] = '#,##0.00';
        $formats[Coordinate::stringFromColumnIndex($col + 2)] = '0.0%';

        if ($this->hasCompare()) {
            $formats[Coordinate::stringFromColumnIndex($col + 3)] = '#,##0.00';
            $formats[Coordinate::stringFromColumnIndex($col + 4)] = '0.0%';
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [
            1 => ['font' => ['bold' => true]],
            $sheet->getHighestRow() => ['font' => ['bold' => true]],
        ];
    }

}

class CounterpartyMonthsSheet implements FromArray, WithHeadings, WithColumnFormatting, WithStyles, WithTitle, ShouldAutoSize
{

    use NeutralizesFormulas;

    public function __construct(protected array $matrix)
    {
    }

    public function title(): string
    {
        return mb_substr(__('databackd-reports-counterparties.sheet_months'), 0, 31);
    }

    public function headings(): array
    {
        $h = [__('databackd-reports-counterparties.columns.counterparty')];

        foreach ($this->matrix['buckets'] as $b) {
            $h[] = $b['label'];
        }

        $h[] = __('databackd-reports-common.total');

        return $h;
    }

    public function array(): array
    {
        $out = [];

        // S6/P4: $r['label'] is the counterparty name (user/import-controlled) - neutralized against CSV/Excel
        // formula injection.
        foreach ($this->matrix['rows'] as $r) {
            $out[] = array_merge([self::neutralizeFormula($r['label'])], array_map('floatval', $r['values']), [(float)$r['total']]);
        }

        $out[] = array_merge([__('databackd-reports-common.total')], array_map('floatval', $this->matrix['totals']), [(float)$this->matrix['grand']]);

        return $out;
    }

    public function columnFormats(): array
    {
        $formats = [];
        $count = count($this->matrix['buckets']) + 1;

        for ($i = 2; $i <= $count + 1; $i++) {
            $formats[Coordinate::stringFromColumnIndex($i)] = '#,##0.00';
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('B2');

        return [
            1 => ['font' => ['bold' => true]],
            $sheet->getHighestRow() => ['font' => ['bold' => true]],
        ];
    }

}
