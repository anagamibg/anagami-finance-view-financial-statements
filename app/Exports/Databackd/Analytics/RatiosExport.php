<?php

namespace App\Exports\Databackd\Analytics;

use App\Exports\Databackd\Concerns\NeutralizesFormulas;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinRatio;
use App\Models\Databackd\FinConsolidationGroup;
use App\Services\Databackd\Analytics\ConsolidationService;
use App\Services\Databackd\Analytics\RatioCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export of the ratios (modes: dashboard = one value per ratio, comparison = ratios x legal entities + group).
 *
 * The class is also the single place where the period / legal entity query parameters of the ratio screens are
 * resolved and where the comparison matrix is calculated, so the screen and the export always show the same numbers:
 *
 *   RatiosExport::resolvePeriod($request)   period_type, period, from, to  ->  array
 *   RatiosExport::resolveEntity($request)   entity (all | id | group:<id>)  ->  array
 *   (new RatiosExport(...))->comparison()   matrix for the comparison screen
 */
class RatiosExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{

    use NeutralizesFormulas;

    const MODES = ['dashboard', 'comparison'];
    const PERIOD_TYPES = ['month', 'quarter', 'year'];

    const STATUS_FILLS = [
        'good' => 'FFD4EDDA',
        'warning' => 'FFFFF3CD',
        'bad' => 'FFF8D7DA',
    ];

    protected RatioCalculator $calc;
    protected ?Collection $ratios = null;
    protected ?Collection $entities = null;
    protected ?array $comparison = null;
    protected ?array $dashboardRows = null;
    protected $resolver = false;

    /** @var array<int, array{row: int, col: int, status: string}> cells to colour */
    protected array $coloured = [];

    /** @var array<int, array{row: int, col: int, format: string}> */
    protected array $numberFormats = [];

    /**
     * @param string $mode dashboard|comparison
     * @param int[] $entityIds legal entities for the dashboard mode (empty = all)
     */
    public function __construct(
        protected string $mode,
        protected Carbon $from,
        protected Carbon $to,
        protected array $entityIds = [],
        ?RatioCalculator $calc = null,
        protected string $periodLabel = '',
        protected ?int $groupId = null
    ) {
        $this->mode = in_array($mode, self::MODES, true) ? $mode : 'dashboard';
        $this->calc = $calc ?? new RatioCalculator(null, company()->id);
    }

    /**
     * Builds the export from the request query (mode, period_type, period, from, to, entity).
     */
    public static function fromRequest(Request $request): self
    {
        $period = self::resolvePeriod($request->query());
        $entity = self::resolveEntity($request->query('entity'));

        return new self((string)$request->query('mode', 'dashboard'), $period['from'], $period['to'], $entity['ids'], null, $period['label'], $entity['group']?->id);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function fileName(): string
    {
        return 'databackd-' . __('databackd-analytics-ratio-detail.export.file_' . $this->mode) . '-'
            . $this->from->format('Ymd') . '-' . $this->to->format('Ymd') . '.xlsx';
    }

    // ---------------------------------------------------------------------------------------------------------
    // Query parameters
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Resolves the period of the ratio screens.
     *
     * Accepts `from` + `to` (Y-m-d or the company date format; a whole month / quarter / year is recognised), or
     * `period_type` (month|quarter|year) + `period` (2026-10, 2026-Q3 or 2026; any of them is aligned to the type).
     * Without parameters it is the current month. A period that ends after today is clipped to today ("to"), the
     * full period end stays in "full_to" (used to find the previous period).
     *
     * @return array{type: string, key: string, label: string, from: Carbon, to: Carbon, full_to: Carbon, partial: bool}
     */
    public static function resolvePeriod(array $input, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $from = self::parseDate($input['from'] ?? null);
        $to = self::parseDate($input['to'] ?? null);
        $type = (string)($input['period_type'] ?? '');

        if ($from && $to) {
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }

            $detected = self::detectType($from, $to);
            $fullTo = $detected ? self::endOf($detected, $from) : $to->copy();
            $type = $detected ?: 'custom';
        } else {
            $type = in_array($type, self::PERIOD_TYPES, true) ? $type : 'month';
            $anchor = self::parsePeriodKey((string)($input['period'] ?? ''), $type) ?? $today->copy();
            $from = self::startOf($type, $anchor);
            $fullTo = self::endOf($type, $anchor);
        }

        $to = $fullTo->min($today);

        if ($to->lt($from)) {
            $to = $from->copy();
        }

        return [
            'type' => $type,
            'key' => $type === 'custom' ? $from->toDateString() . '_' . $to->toDateString() : self::periodKey($type, $from),
            'label' => self::periodLabel($type, $from, $to),
            'from' => $from,
            'to' => $to,
            'full_to' => $fullTo,
            'partial' => $to->lt($fullTo),
        ];
    }

    /**
     * Selectable periods for the filter: the last months (18), quarters (6) or years (2) up to today.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function periodOptions(string $type, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $out = [];

        if ($type === 'quarter') {
            $cursor = $today->copy()->startOfQuarter();

            for ($i = 0; $i < 7; $i++) {
                $out[] = ['key' => self::periodKey('quarter', $cursor), 'label' => self::periodLabel('quarter', $cursor, $cursor)];
                $cursor = $cursor->copy()->subQuarterNoOverflow();
            }
        } elseif ($type === 'year') {
            for ($i = 0; $i < 2; $i++) {
                $d = $today->copy()->startOfYear()->subYears($i);
                $out[] = ['key' => self::periodKey('year', $d), 'label' => self::periodLabel('year', $d, $d)];
            }
        } else {
            $cursor = $today->copy()->startOfMonth();

            for ($i = 0; $i < 18; $i++) {
                $out[] = ['key' => self::periodKey('month', $cursor), 'label' => self::periodLabel('month', $cursor, $cursor)];
                $cursor = $cursor->copy()->subMonthNoOverflow();
            }
        }

        return $out;
    }

    /**
     * Resolves the legal entity filter: `all` (default), an entity id, or `group:<id>` (consolidated data of the group).
     *
     * @return array{ids: int[], entity: ?FinEntity, value: string, group_id: ?int, group: ?FinConsolidationGroup}
     */
    public static function resolveEntity($value): array
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        $groupId = null;
        $group = null;

        if (str_starts_with($value, 'group:')) {
            $group = FinConsolidationGroup::find((int)substr($value, 6));
            $groupId = $group?->id;

            if ($group) {
                return ['ids' => [], 'entity' => null, 'value' => 'group:' . $group->id, 'group_id' => $groupId, 'group' => $group];
            }

            $value = 'all';
        }

        if ($value !== '' && ctype_digit($value)) {
            $entity = FinEntity::find((int)$value);

            if ($entity) {
                return ['ids' => [(int)$entity->id], 'entity' => $entity, 'value' => (string)$entity->id, 'group_id' => null, 'group' => null];
            }
        }

        return ['ids' => [], 'entity' => null, 'value' => 'all', 'group_id' => null, 'group' => null];
    }

    /** Consolidation groups for the "Consolidated" option of the legal entity filter. */
    public static function consolidationGroups(): Collection
    {
        return FinConsolidationGroup::orderBy('name')->get(['id', 'name']);
    }

    /**
     * Resolver that replaces formula variables with consolidated values of the group (null for a legal entity filter).
     */
    public static function groupResolver(?FinConsolidationGroup $group, RatioCalculator $calc): ?callable
    {
        return $group ? (new ConsolidationService())->ratioResolver($group, $calc->engine()) : null;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Data
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Active ratios of the company in screen order (group, sort, name).
     *
     * @return Collection<int, FinRatio>
     */
    public function ratios(): Collection
    {
        if ($this->ratios === null) {
            $order = array_flip(FinRatio::GROUPS);
            $this->ratios = FinRatio::where('is_active', 1)->orderBy('sort')->orderBy('id')->get()
                ->sortBy(fn(FinRatio $r) => [$order[$r->group] ?? 99, (int)$r->sort, $r->id])
                ->values();
        }

        return $this->ratios;
    }

    /**
     * Active legal entities.
     *
     * @return Collection<int, FinEntity>
     */
    public function entities(): Collection
    {
        return $this->entities ??= FinEntity::where('is_active', 1)->orderBy('sort')->orderBy('name')->get();
    }

    /**
     * The comparison matrix (ratios x legal entities + group), shared by the screen and the Excel export.
     *
     * @return array{
     *   entities: Collection<int, FinEntity>,
     *   groups: array<string, array<int, array{ratio: FinRatio, cells: array<int, array{value: ?float, status: string, text: string}>, group: array{value: ?float, status: string, text: string}}>>,
     *   summary: array<int|string, array{good: int, warning: int, bad: int, none: int, total: int}>
     * } `summary` is keyed by entity id and `group`
     */
    public function comparison(): array
    {
        if ($this->comparison !== null) {
            return $this->comparison;
        }

        $entities = $this->entities();
        $allIds = $entities->pluck('id')->map(fn($id) => (int)$id)->all();
        $groups = [];
        $summary = ['group' => ['good' => 0, 'warning' => 0, 'bad' => 0, 'none' => 0, 'total' => 0]];

        foreach ($entities as $e) {
            $summary[$e->id] = ['good' => 0, 'warning' => 0, 'bad' => 0, 'none' => 0, 'total' => 0];
        }

        foreach ($this->ratios() as $ratio) {
            $cells = [];

            foreach ($entities as $e) {
                $cells[$e->id] = $this->cell($ratio, [(int)$e->id]);
                $summary[$e->id][$cells[$e->id]['status']]++;
                $summary[$e->id]['total']++;
            }

            $group = $this->cell($ratio, $allIds);
            $summary['group'][$group['status']]++;
            $summary['group']['total']++;

            $groups[$ratio->group][] = ['ratio' => $ratio, 'cells' => $cells, 'group' => $group];
        }

        return $this->comparison = ['entities' => $entities, 'groups' => $groups, 'summary' => $summary];
    }

    /**
     * One value per ratio for the selected legal entities (dashboard mode).
     *
     * @return array<int, array{ratio: FinRatio, value: ?float, status: string, text: string}>
     */
    public function dashboardRows(): array
    {
        if ($this->dashboardRows !== null) {
            return $this->dashboardRows;
        }

        $rows = [];

        foreach ($this->ratios() as $ratio) {
            $rows[] = ['ratio' => $ratio] + $this->cell($ratio, $this->entityIds);
        }

        return $this->dashboardRows = $rows;
    }

    /**
     * @param int[] $entityIds
     * @return array{value: ?float, status: string, text: string}
     */
    protected function cell(FinRatio $ratio, array $entityIds): array
    {
        if ($this->resolver === false) {
            $group = $this->groupId ? FinConsolidationGroup::find($this->groupId) : null;
            $this->resolver = self::groupResolver($group, $this->calc);
        }

        $value = $this->calc->calculate($ratio, $this->from, $this->to, $entityIds ? ['entity_ids' => $entityIds] : [], $this->resolver ?: null);

        return ['value' => $value, 'status' => $this->calc->status($ratio, $value), 'text' => RatioCalculator::format($ratio, $value)];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Maatwebsite\Excel
    // ---------------------------------------------------------------------------------------------------------

    public function title(): string
    {
        return mb_substr(__('databackd-analytics-ratio-detail.export.sheet_' . $this->mode), 0, 31);
    }

    public function headings(): array
    {
        $t = fn(string $key) => __('databackd-analytics-ratio-detail.export.' . $key);

        if ($this->mode === 'comparison') {
            // S6/P4: legal-entity names (user-controlled) are used as column headers here - neutralized against
            // CSV/Excel formula injection.
            $entityNames = array_map([self::class, 'neutralizeFormula'], $this->entities()->pluck('name')->all());

            return array_merge(
                [$t('code'), $t('name'), $t('group'), $t('format'), $t('target_min'), $t('target_max')],
                $entityNames,
                [$t('group_column')]
            );
        }

        return [$t('code'), $t('name'), $t('group'), $t('format'), $t('value'), $t('target_min'), $t('target_max'), $t('status')];
    }

    public function array(): array
    {
        $this->coloured = [];
        $this->numberFormats = [];
        $out = [];
        $rowNo = 1; // heading row

        if ($this->mode === 'comparison') {
            foreach ($this->comparison()['groups'] as $rows) {
                foreach ($rows as $row) {
                    $rowNo++;
                    $ratio = $row['ratio'];
                    $line = array_merge($this->ratioColumns($ratio), [$this->excelValue($ratio, $ratio->target_min), $this->excelValue($ratio, $ratio->target_max)]);
                    $col = count($line);

                    foreach ($row['cells'] as $cell) {
                        $col++;
                        $line[] = $this->excelValue($ratio, $cell['value']);
                        $this->mark($rowNo, $col, $cell['status'], $ratio);
                    }

                    $col++;
                    $line[] = $this->excelValue($ratio, $row['group']['value']);
                    $this->mark($rowNo, $col, $row['group']['status'], $ratio);
                    $this->numberFormats[] = ['row' => $rowNo, 'col' => 5, 'format' => $this->numberFormat($ratio)];
                    $this->numberFormats[] = ['row' => $rowNo, 'col' => 6, 'format' => $this->numberFormat($ratio)];
                    $out[] = $line;
                }
            }

            return $out;
        }

        foreach ($this->dashboardRows() as $row) {
            $rowNo++;
            $ratio = $row['ratio'];
            $out[] = array_merge($this->ratioColumns($ratio), [
                $this->excelValue($ratio, $row['value']), $this->excelValue($ratio, $ratio->target_min), $this->excelValue($ratio, $ratio->target_max),
                __('databackd-analytics-common.statuses.' . $row['status']),
            ]);
            $this->mark($rowNo, 5, $row['status'], $ratio);
            $this->numberFormats[] = ['row' => $rowNo, 'col' => 6, 'format' => $this->numberFormat($ratio)];
            $this->numberFormats[] = ['row' => $rowNo, 'col' => 7, 'format' => $this->numberFormat($ratio)];
        }

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        // array() has already run (the export builds the rows before the styles)
        foreach ($this->coloured as $c) {
            $coordinate = Coordinate::stringFromColumnIndex($c['col']) . $c['row'];
            $style = $sheet->getStyle($coordinate);
            $style->getNumberFormat()->setFormatCode($c['format']);

            if (isset(self::STATUS_FILLS[$c['status']])) {
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::STATUS_FILLS[$c['status']]);
            }
        }

        foreach ($this->numberFormats as $f) {
            $sheet->getStyle(Coordinate::stringFromColumnIndex($f['col']) . $f['row'])->getNumberFormat()->setFormatCode($f['format']);
        }

        $sheet->freezePane('C2');

        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8EEF3']]],
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------

    protected function ratioColumns(FinRatio $ratio): array
    {
        // S6/P4: a custom ratio's code/name (fin_ratios, user-created via the ratio builder) is neutralized
        // against CSV/Excel formula injection.
        return [
            self::neutralizeFormula($ratio->code),
            self::neutralizeFormula($ratio->name),
            __('databackd-analytics-common.ratio_groups.' . $ratio->group),
            __('databackd-analytics-common.ratio_formats.' . $ratio->format),
        ];
    }

    protected function mark(int $row, int $col, string $status, FinRatio $ratio): void
    {
        $this->coloured[] = ['row' => $row, 'col' => $col, 'status' => $status, 'format' => $this->numberFormat($ratio)];
    }

    /** Percent ratios are stored as fractions (12.5 -> 0.125) so that the Excel percent format shows them correctly. */
    protected function excelValue(FinRatio $ratio, ?float $value): ?float
    {
        return $value !== null && $ratio->format === 'percent' ? $value / 100 : $value;
    }

    protected function numberFormat(FinRatio $ratio): string
    {
        switch ($ratio->format) {
            case 'percent':
                return '0.0%';
            case 'days':
                return '0" ' . __('databackd-analytics-common.days_unit') . '"';
            default:
                return '#,##0.00';
        }
    }

    protected static function parseDate($value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        foreach (array_filter(['Y-m-d', company()->date_format ?? null, 'd.m.Y']) as $format) {
            try {
                $d = Carbon::createFromFormat('!' . $format, $value);

                if ($d && $d->format($format) === $value) {
                    return $d->startOfDay();
                }
            } catch (\Throwable $e) {
                // try the next format
            }
        }

        return null;
    }

    protected static function parsePeriodKey(string $key, string $type): ?Carbon
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-?Q([1-4])$/i', $key, $m)) {
            return Carbon::create((int)$m[1], ((int)$m[2] - 1) * 3 + 1, 1)->startOfDay();
        }

        if (preg_match('/^(\d{4})-(\d{1,2})(?:-\d{1,2})?$/', $key, $m) && (int)$m[2] >= 1 && (int)$m[2] <= 12) {
            return Carbon::create((int)$m[1], (int)$m[2], 1)->startOfDay();
        }

        if (preg_match('/^(\d{4})$/', $key, $m)) {
            return Carbon::create((int)$m[1], 1, 1)->startOfDay();
        }

        return null;
    }

    protected static function startOf(string $type, Carbon $d): Carbon
    {
        $d = $d->copy()->startOfDay();

        return $type === 'year' ? $d->startOfYear() : ($type === 'quarter' ? $d->startOfQuarter() : $d->startOfMonth());
    }

    protected static function endOf(string $type, Carbon $d): Carbon
    {
        $d = $d->copy()->startOfDay();

        return ($type === 'year' ? $d->endOfYear() : ($type === 'quarter' ? $d->endOfQuarter() : $d->endOfMonth()))->startOfDay();
    }

    protected static function detectType(Carbon $from, Carbon $to): ?string
    {
        foreach (['month', 'quarter', 'year'] as $type) {
            if ($from->isSameDay(self::startOf($type, $from)) && $to->isSameDay(self::endOf($type, $from))) {
                return $type;
            }
        }

        return null;
    }

    protected static function periodKey(string $type, Carbon $d): string
    {
        return $type === 'year' ? $d->format('Y') : ($type === 'quarter' ? $d->format('Y') . '-Q' . $d->quarter : $d->format('Y-m'));
    }

    protected static function periodLabel(string $type, Carbon $from, Carbon $to): string
    {
        $t = fn(string $key, array $r = []) => __('databackd-analytics-ratio-detail.' . $key, $r);

        switch ($type) {
            case 'month':
                return $t('months.' . $from->month) . ' ' . $from->year;
            case 'quarter':
                return $t('quarter_label', ['quarter' => $from->quarter, 'year' => $from->year]);
            case 'year':
                return (string)$from->year;
            default:
                return $from->format('d.m.Y') . ' – ' . $to->format('d.m.Y');
        }
    }

}
