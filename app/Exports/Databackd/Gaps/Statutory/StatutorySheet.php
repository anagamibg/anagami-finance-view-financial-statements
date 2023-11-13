<?php

namespace App\Exports\Databackd\Gaps\Statutory;

use App\Exports\Databackd\Concerns\NeutralizesFormulas;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One sheet of StatutoryExport. Columns: code | item | accounts | current year | previous year.
 */
class StatutorySheet implements FromArray, WithTitle, WithStyles, WithColumnFormatting, WithColumnWidths, WithStrictNullComparison
{

    // AFV (S10): entity / company / item / account names and the text lines are neutralized against CSV/Excel
    // formula injection, the amount columns stay numbers
    use NeutralizesFormulas;

    const NUMBER_FORMAT = '#,##0.00;[Red]-#,##0.00';

    protected array $statement;
    protected array $lines = [];
    protected int $headerRow = 1;
    /** @var array<int, array{row: int, type: string, level: int}> */
    protected array $rowMeta = [];
    protected int $titleRows = 0;

    public function __construct(protected array $document, protected string $kind)
    {
        $this->statement = $document[$kind];
        $this->prepare();
    }

    public function array(): array
    {
        return $this->lines;
    }

    public function title(): string
    {
        return mb_substr(__('databackd-gaps-statutory.export.sheet_' . $this->kind), 0, 31);
    }

    protected function prepare(): void
    {
        $d = $this->document;
        $s = $this->statement;
        [$title, $comparative] = StatutoryDocument::titles($s);

        $this->lines[] = [self::neutralizeFormula($d['info']['name'])]; // AFV (S10)

        foreach (StatutoryDocument::infoLines($d['info']) as $line) {
            $this->lines[] = [self::neutralizeFormula($line)]; // AFV (S10)
        }

        $this->lines[] = [self::neutralizeFormula($title)]; // AFV (S10)
        $this->lines[] = [self::neutralizeFormula($comparative)];
        $this->lines[] = [__('databackd-gaps-statutory.document.currency_note', ['currency' => $s['currency']])];
        $this->lines[] = [__('databackd-gaps-statutory.document.disclaimer')];
        $this->lines[] = [];
        $this->titleRows = count($this->lines);
        $this->headerRow = $this->titleRows + 1;

        $this->lines[] = [
            __('databackd-gaps-statutory.columns.code'),
            __('databackd-gaps-statutory.columns.item'),
            __('databackd-gaps-statutory.columns.accounts'),
            __('databackd-gaps-statutory.columns.current') . ' (' . ($s['kind'] === 'balance' ? date('d.m.Y', strtotime($s['dates']['current'])) : $s['year']) . ')',
            __('databackd-gaps-statutory.columns.previous') . ' (' . ($s['kind'] === 'balance' ? date('d.m.Y', strtotime($s['dates']['previous'])) : $s['previous_year']) . ')',
        ];

        foreach ($s['rows'] as $row) {
            $isHeading = $row['type'] === 'heading';
            // AFV (S10): code / item name / account codes are text - neutralized against formula injection
            $this->lines[] = [
                self::neutralizeFormula($row['code']),
                self::neutralizeFormula(str_repeat('    ', max($row['level'] - 1, 0)) . $row['name']),
                self::neutralizeFormula(implode(', ', $row['accounts'])),
                $isHeading ? null : (float)$row['current'],
                $isHeading ? null : (float)$row['previous'],
            ];
            $this->rowMeta[] = ['row' => count($this->lines), 'type' => $row['type'], 'level' => $row['level']];
        }

        $this->lines[] = [];
        $this->lines[] = [__('databackd-gaps-statutory.notes.title')];

        foreach (array_merge($s['notes'], [__('databackd-gaps-statutory.notes.accounts_column')]) as $note) {
            $this->lines[] = [self::neutralizeFormula($note)]; // AFV (S10)
        }

        if (!empty($d['warnings'])) {
            $this->lines[] = [];
            $this->lines[] = [__('databackd-gaps-statutory.warnings.title')];

            foreach ($d['warnings'] as $warning) {
                $this->lines[] = [self::neutralizeFormula($warning['text'])]; // AFV (S10)
            }
        }
    }

    public function columnWidths(): array
    {
        return ['A' => 11, 'B' => 62, 'C' => 20, 'D' => 20, 'E' => 20];
    }

    public function columnFormats(): array
    {
        return ['D' => self::NUMBER_FORMAT, 'E' => self::NUMBER_FORMAT];
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            $this->headerRow => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D3A5F']],
                'alignment' => ['wrapText' => true],
            ],
            $this->titleRows - 1 => ['font' => ['italic' => true, 'color' => ['rgb' => '7A5A00']]],
        ];

        // The statement title row: the bold line before the comparative one
        $styles[$this->titleRows - 4] = ['font' => ['bold' => true, 'size' => 12]];

        foreach ($this->rowMeta as $meta) {
            $range = 'A' . $meta['row'] . ':E' . $meta['row'];

            if ($meta['type'] === 'heading') {
                $styles[$range] = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E2EE']]];
            }
            elseif ($meta['type'] === 'total' || $meta['type'] === 'result') {
                $styles[$range] = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBFB']]];
            }
            elseif ($meta['type'] === 'subtotal') {
                $styles[$range] = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F4F7']]];
            }
            elseif ($meta['type'] === 'memo') {
                $styles[$range] = ['font' => ['italic' => true, 'color' => ['rgb' => '595959']]];
            }
        }

        $sheet->freezePane('A' . ($this->headerRow + 1));

        return $styles;
    }

}
