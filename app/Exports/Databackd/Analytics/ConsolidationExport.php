<?php

namespace App\Exports\Databackd\Analytics;

use App\Exports\Databackd\Concerns\NeutralizesFormulas;
use App\Models\Databackd\FinAccount;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export of the consolidation worksheet: one sheet with the consolidated income statement and one with the
 * consolidated balance sheet, both with all columns (entities, eliminations, adjustments, non-controlling interest,
 * consolidated).
 *
 * The same class also builds the statement rows shown on screen (statementRows()), so the screen and the file
 * always show the same structure and subtotals.
 */
class ConsolidationExport implements WithMultipleSheets
{

    /** Order of the sub-groups (by fin_accounts.subtype) inside each account type; '_none' = no subtype. */
    const GROUP_ORDER = [
        'revenue' => ['_none', 'other', 'interest'],
        'expense' => ['cogs', 'payroll', 'opex', 'depreciation', 'interest', 'tax', 'other'],
        'asset' => ['non_current_asset', 'inventory', 'receivable', 'cash', 'current_asset', 'other'],
        'liability' => ['non_current_liability', 'current_liability'],
        'equity' => ['_none'],
    ];

    /**
     * @param array $result      ConsolidationService::run() result
     * @param array $subtypes    [account_id => subtype|null]
     * @param array $info        group (name), period (label), method (label), currency
     */
    public function __construct(protected array $result, protected array $subtypes, protected array $info)
    {
    }

    public function sheets(): array
    {
        $entities = $this->result['entities'] ?? [];

        return [
            new ConsolidationStatementSheet(
                self::statementRows($this->result, 'pnl', $this->subtypes),
                $entities,
                $this->info + ['sheet' => 'pnl'],
            ),
            new ConsolidationStatementSheet(
                self::statementRows($this->result, 'balance', $this->subtypes),
                $entities,
                $this->info + ['sheet' => 'balance'],
            ),
        ];
    }

    /**
     * Structured rows of one statement with sub-group and type subtotals.
     *
     * Row: ['kind' => section|group|account|subtotal|total|result, 'label' => string, 'code' => string|null,
     *       'account_id' => int|null, 'type' => string|null, 'cells' => null|['entities' => [id => float],
     *       'eliminations' => float, 'adjustments' => float, 'minority' => float, 'consolidated' => float]]
     *
     * @param string $statement pnl|balance
     */
    public static function statementRows(array $result, string $statement, array $subtypes): array
    {
        $entityIds = array_keys($result['entities'] ?? []);
        $lines = $result[$statement] ?? [];

        if (empty($lines)) {
            $types = $statement === 'pnl' ? ['revenue', 'expense'] : ['asset', 'liability', 'equity'];
            $lines = array_values(array_filter($result['lines'] ?? [], fn($l) => in_array($l['type'] ?? null, $types, true)));
        }

        $rows = [];
        $totals = [];

        $types = $statement === 'pnl' ? ['revenue', 'expense'] : ['asset', 'liability', 'equity'];

        foreach ($types as $type) {
            $typeLines = array_values(array_filter($lines, fn($l) => ($l['type'] ?? null) === $type));

            if (empty($typeLines)) {
                $totals[$type] = self::emptyCells($entityIds);
                continue;
            }

            // Bucket the accounts by sub-group
            $buckets = [];

            foreach ($typeLines as $line) {
                $subtype = $subtypes[$line['account_id']] ?? null;
                $key = $subtype ?: '_none';
                $buckets[$key][] = $line;
            }

            $order = self::GROUP_ORDER[$type];
            $keys = array_values(array_unique(array_merge(
                array_values(array_intersect($order, array_keys($buckets))),
                array_keys($buckets)
            )));

            $rows[] = ['kind' => 'section', 'label' => __('databackd-analytics-consolidation.sections.' . $type), 'code' => null, 'account_id' => null, 'type' => $type, 'cells' => null];

            $typeTotal = self::emptyCells($entityIds);
            $showGroups = count($keys) > 1;

            foreach ($keys as $key) {
                $bucket = $buckets[$key];
                usort($bucket, fn($a, $b) => strcmp((string)$a['code'], (string)$b['code']));
                $groupLabel = self::groupLabel($type, $key);

                if ($showGroups) {
                    $rows[] = ['kind' => 'group', 'label' => $groupLabel, 'code' => null, 'account_id' => null, 'type' => $type, 'cells' => null];
                }

                $groupTotal = self::emptyCells($entityIds);

                foreach ($bucket as $line) {
                    $cells = self::cellsOf($line, $entityIds);
                    $groupTotal = self::addCells($groupTotal, $cells);
                    $rows[] = [
                        'kind' => 'account',
                        'label' => (string)$line['name'],
                        'code' => (string)$line['code'],
                        'account_id' => (int)$line['account_id'],
                        'type' => $type,
                        'cells' => $cells,
                    ];
                }

                if ($showGroups) {
                    $rows[] = [
                        'kind' => 'subtotal',
                        'label' => __('databackd-analytics-consolidation.totals.group', ['name' => mb_strtolower($groupLabel)]),
                        'code' => null, 'account_id' => null, 'type' => $type, 'cells' => $groupTotal,
                    ];
                }

                $typeTotal = self::addCells($typeTotal, $groupTotal);
            }

            $totals[$type] = $typeTotal;
            $rows[] = ['kind' => 'total', 'label' => __('databackd-analytics-consolidation.totals.' . $type), 'code' => null, 'account_id' => null, 'type' => $type, 'cells' => $typeTotal];
        }

        if ($statement === 'pnl') {
            $rows[] = [
                'kind' => 'result', 'label' => __('databackd-analytics-consolidation.totals.result'), 'code' => null, 'account_id' => null, 'type' => 'result',
                'cells' => self::addCells($totals['revenue'], $totals['expense'], -1),
            ];
        }
        else {
            $rows[] = [
                'kind' => 'result', 'label' => __('databackd-analytics-consolidation.totals.liabilities_equity'), 'code' => null, 'account_id' => null, 'type' => 'liabilities_equity',
                'cells' => self::addCells($totals['liability'], $totals['equity']),
            ];
        }

        return $rows;
    }

    /** Net values of one statement column (used for the cards and the charts). */
    public static function typeTotals(array $result, array $subtypes): array
    {
        $entityIds = array_keys($result['entities'] ?? []);
        $out = ['revenue' => self::emptyCells($entityIds), 'expense' => self::emptyCells($entityIds)];

        foreach (self::statementRows($result, 'pnl', $subtypes) as $row) {
            if ($row['kind'] === 'total' && isset($out[$row['type']])) {
                $out[$row['type']] = $row['cells'];
            }
        }

        return $out;
    }

    protected static function groupLabel(string $type, string $key): string
    {
        if ($key === '_none') {
            return $type === 'revenue'
                ? __('databackd-analytics-consolidation.groups.operating_revenue')
                : __('databackd-analytics-consolidation.sections.' . $type);
        }

        return __('databackd-analytics-common.account_subtypes.' . $key);
    }

    protected static function emptyCells(array $entityIds): array
    {
        return [
            'entities' => array_fill_keys($entityIds, 0.0),
            'eliminations' => 0.0,
            'adjustments' => 0.0,
            'minority' => 0.0,
            'consolidated' => 0.0,
        ];
    }

    protected static function cellsOf(array $line, array $entityIds): array
    {
        // A contra account (e.g. accumulated depreciation) always reduces the total, whatever sign the service returns
        $contra = !empty($line['contra']) || FinAccount::isContraCode((string)($line['code'] ?? '')); // review A: 2411 under 241 too
        $v = fn($x) => $contra ? -abs((float)$x) : (float)$x;

        $entities = [];

        foreach ($entityIds as $id) {
            $entities[$id] = $v($line['by_entity'][$id] ?? 0);
        }

        return [
            'entities' => $entities,
            'eliminations' => (float)($line['eliminations'] ?? 0),
            'adjustments' => (float)($line['adjustments'] ?? 0),
            'minority' => $v($line['minority'] ?? 0),
            'consolidated' => $v($line['consolidated'] ?? 0),
        ];
    }

    protected static function addCells(array $a, array $b, int $sign = 1): array
    {
        foreach ($a['entities'] as $id => $value) {
            $a['entities'][$id] = $value + $sign * ($b['entities'][$id] ?? 0);
        }

        foreach (['eliminations', 'adjustments', 'minority', 'consolidated'] as $key) {
            $a[$key] += $sign * $b[$key];
        }

        return $a;
    }

}

/**
 * One worksheet of the consolidation export (income statement or balance sheet).
 */
class ConsolidationStatementSheet implements FromArray, WithTitle, WithStyles, WithColumnFormatting, ShouldAutoSize
{

    use NeutralizesFormulas;

    /** Number of rows above the table header: title, group, period / method, empty line. */
    const INTRO_ROWS = 4;

    protected array $boldRows = [];

    public function __construct(protected array $rows, protected array $entities, protected array $info)
    {
    }

    public function title(): string
    {
        return mb_substr(__('databackd-analytics-consolidation.export.sheet_' . $this->info['sheet']), 0, 31);
    }

    public function array(): array
    {
        $t = fn($key) => __('databackd-analytics-consolidation.' . $key);
        $pad = fn(array $row) => array_pad($row, $this->columnCount(), null);

        // S6/P4: the consolidation group name and the legal-entity names are user-controlled - neutralized
        // against CSV/Excel formula injection, same as the row code/label below.
        $data = [];
        $data[] = $pad([$t('export.title_' . $this->info['sheet'])]);
        $data[] = $pad([$t('export.group') . ': ' . self::neutralizeFormula($this->info['group']), null, $t('export.period') . ': ' . $this->info['period']]);
        $data[] = $pad([$t('export.method') . ': ' . $this->info['method'], null, $t('export.currency') . ': ' . $this->info['currency']]);
        $data[] = $pad([]);

        $heading = [$t('export.code'), $t('columns.account')];

        foreach ($this->entities as $name) {
            $heading[] = self::neutralizeFormula($name);
        }

        $heading[] = $t('columns.eliminations');
        $heading[] = $t('columns.adjustments');
        $heading[] = $t('columns.minority');
        $heading[] = $t('columns.consolidated');
        $data[] = $heading;

        foreach ($this->rows as $row) {
            $line = [self::neutralizeFormula($row['code']), self::neutralizeFormula($row['label'])];

            if ($row['cells'] === null) {
                $line = $pad($line);
            }
            else {
                foreach (array_keys($this->entities) as $id) {
                    $line[] = round($row['cells']['entities'][$id] ?? 0, 2);
                }

                $line[] = round($row['cells']['eliminations'], 2);
                $line[] = round($row['cells']['adjustments'], 2);
                $line[] = round($row['cells']['minority'], 2);
                $line[] = round($row['cells']['consolidated'], 2);
            }

            $data[] = $line;

            if ($row['kind'] !== 'account') {
                $this->boldRows[] = count($data);
            }
        }

        return $data;
    }

    public function columnFormats(): array
    {
        $formats = [];

        for ($i = 3; $i <= $this->columnCount(); $i++) {
            $formats[Coordinate::stringFromColumnIndex($i)] = '#,##0.00;[Red]-#,##0.00';
        }

        $formats['A'] = '@';

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        // Make sure array() ran so that $boldRows is filled
        if (empty($this->boldRows)) {
            $this->array();
        }

        $headingRow = self::INTRO_ROWS + 1;
        $lastColumn = Coordinate::stringFromColumnIndex($this->columnCount());
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            $headingRow => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D82F5']],
            ],
        ];

        foreach ($this->rows as $i => $row) {
            $excelRow = $headingRow + 1 + $i;

            if ($row['kind'] === 'section') {
                $styles[$excelRow] = [
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2F7']],
                ];
            }
            elseif ($row['kind'] === 'group') {
                $styles[$excelRow] = ['font' => ['bold' => true, 'italic' => true]];
            }
            elseif (in_array($row['kind'], ['subtotal', 'total', 'result'], true)) {
                $styles[$excelRow] = ['font' => ['bold' => true]];
            }
        }

        // Highlight the "Consolidated" column
        $styles[$lastColumn] = ['font' => ['bold' => true]];

        return $styles;
    }

    protected function columnCount(): int
    {
        // code + account + entities + eliminations + adjustments + minority + consolidated
        return 2 + count($this->entities) + 4;
    }

}
