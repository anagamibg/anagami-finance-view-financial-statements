<?php

namespace App\Exports\Databackd\Reports;

use App\Services\Databackd\FinancialDataService;
use App\Services\Databackd\Reports\PeriodBuckets;
use App\Services\Databackd\Reports\ReportBuilder;
use App\Services\Databackd\Reports\ReportFilters;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Data of the counterparties report (screen, detail, Excel and PDF). Works without a logged in user:
 * the company is passed to the constructor.
 *
 * Turnover = SUM(amount_base) of the flow entries on revenue (clients) and/or expense (suppliers) accounts.
 * Documents = COUNT(DISTINCT document_no); entries without a document number count as one document each.
 * Everything is aggregated in SQL: one GROUP BY by counterparty, one by counterparty and bucket (sparkline).
 */
class CounterpartyData
{

    const SIDES = ['revenue', 'expense', 'all'];

    /** `name` request value of the entries without a counterparty. */
    const NONE_KEY = '__none__';

    protected FinancialDataService $data;

    public function __construct(protected int $companyId)
    {
        $this->data = new FinancialDataService($companyId);
    }

    public static function normalizeSide(?string $side): string
    {
        return in_array($side, self::SIDES, true) ? $side : 'revenue';
    }

    /** SQL expression of the normalized counterparty name ('' = no counterparty). */
    protected function nameExpr(): string
    {
        return "COALESCE(NULLIF(TRIM(fin_entries.counterparty_name), ''), '')";
    }

    protected function docsExpr(): string
    {
        return "(COUNT(DISTINCT NULLIF(TRIM(fin_entries.document_no), '')) + SUM(CASE WHEN fin_entries.document_no IS NULL OR TRIM(fin_entries.document_no) = '' THEN 1 ELSE 0 END))";
    }

    /** Flow entries of the side (revenue accounts, expense accounts or both) in the range. */
    protected function base(ReportFilters $f, string $side, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $types = $side === 'all' ? ['revenue', 'expense'] : [$side];

        return $this->data->query($f->toServiceFilters())
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->where('fin_entries.kind', 'flow')
            ->whereIn('fin_accounts.type', $types)
            ->whereBetween('fin_entries.entry_date', [($from ?? $f->from)->toDateString(), ($to ?? $f->to)->toDateString()]);
    }

    /** Buckets of the sparkline: months, or days for a range shorter than three months. */
    public function sparkBuckets(ReportFilters $f): array
    {
        $buckets = PeriodBuckets::make($f->from, $f->to, 'month');

        return count($buckets) >= 3 ? $buckets : PeriodBuckets::make($f->from, $f->to, 'day');
    }

    protected function sparkPeriodicity(array $buckets, ReportFilters $f): string
    {
        return count(PeriodBuckets::make($f->from, $f->to, 'month')) >= 3 ? 'month' : 'day';
    }

    /**
     * Counterparties of the side, by turnover descending.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>, kpi: array<string, mixed>, buckets: array, side: string}
     */
    public function listing(ReportFilters $f, string $side): array
    {
        $side = self::normalizeSide($side);
        $name = $this->nameExpr();

        $aggregate = $this->base($f, $side)
            ->selectRaw("$name as cp_name, SUM(fin_entries.amount_base) as total, COUNT(*) as entries, " . $this->docsExpr() . " as docs,
                SUM(CASE WHEN fin_accounts.type = 'revenue' THEN fin_entries.amount_base ELSE 0 END) as revenue,
                SUM(CASE WHEN fin_accounts.type = 'expense' THEN fin_entries.amount_base ELSE 0 END) as expense")
            ->groupBy(DB::raw($name))
            ->get();

        // Sparkline data: one query for all counterparties
        $buckets = $this->sparkBuckets($f);
        $periodicity = $this->sparkPeriodicity($buckets, $f);
        $bucketExpr = PeriodBuckets::sqlExpression($periodicity, 'fin_entries.entry_date');
        $series = [];

        $this->base($f, $side)
            ->selectRaw("$name as cp_name, $bucketExpr as bucket, SUM(fin_entries.amount_base) as total")
            ->groupBy(DB::raw($name), DB::raw($bucketExpr))
            ->get()
            ->each(function ($r) use (&$series) {
                $series[$r->cp_name][$r->bucket] = (float)$r->total;
            });

        // Comparison period
        $previous = [];
        $comparePeriod = $f->comparePeriod();

        if ($comparePeriod) {
            $this->base($f, $side, $comparePeriod['from'], $comparePeriod['to'])
                ->selectRaw("$name as cp_name, SUM(fin_entries.amount_base) as total")
                ->groupBy(DB::raw($name))
                ->get()
                ->each(function ($r) use (&$previous) {
                    $previous[$r->cp_name] = (float)$r->total;
                });
        }

        $grand = round((float)$aggregate->sum('total'), 2);
        $rows = [];

        foreach ($aggregate as $r) {
            $total = round((float)$r->total, 2);
            $docs = (int)$r->docs;
            $values = [];

            foreach ($buckets as $b) {
                $values[] = round($series[$r->cp_name][$b['key']] ?? 0, 2);
            }

            [$trend, $trendPct] = self::trend($values);
            $isNone = $r->cp_name === '';
            $row = [
                'key' => $isNone ? self::NONE_KEY : $r->cp_name,
                'name' => $r->cp_name,
                'label' => $isNone ? __('databackd-reports-counterparties.no_counterparty') : $r->cp_name,
                'is_none' => $isNone,
                'total' => $total,
                'revenue' => round((float)$r->revenue, 2),
                'expense' => round((float)$r->expense, 2),
                'docs' => $docs,
                'entries' => (int)$r->entries,
                'avg' => $docs > 0 ? round($total / $docs, 2) : 0.0,
                'share' => $grand > 0 ? round($total / $grand * 100, 2) : 0.0,
                'spark' => $values,
                'trend' => $trend,
                'trend_pct' => $trendPct,
            ];

            if ($comparePeriod) {
                $prev = round($previous[$r->cp_name] ?? 0, 2);
                $row['previous'] = $prev;
                $row['change_pct'] = ReportBuilder::changePct($total, $prev);
            }

            $rows[] = $row;
        }

        usort($rows, fn($a, $b) => [$b['total'], $a['label']] <=> [$a['total'], $b['label']]);

        $totalDocs = array_sum(array_column($rows, 'docs'));
        $totals = [
            'total' => $grand,
            'revenue' => round(array_sum(array_column($rows, 'revenue')), 2),
            'expense' => round(array_sum(array_column($rows, 'expense')), 2),
            'docs' => $totalDocs,
            'avg' => $totalDocs > 0 ? round($grand / $totalDocs, 2) : 0.0,
            'share' => $grand > 0 ? 100.0 : 0.0,
        ];

        if ($comparePeriod) {
            $totals['previous'] = round(array_sum($previous), 2);
            $totals['change_pct'] = ReportBuilder::changePct($grand, $totals['previous']);
        }

        // KPI: the entries without a counterparty are not a counterparty
        $named = array_values(array_filter($rows, fn($r) => !$r['is_none']));
        $top5 = array_sum(array_column(array_slice($named, 0, 5), 'total'));

        $kpi = [
            'count' => count($named),
            'total' => $grand,
            'top5_share' => $grand > 0 ? round($top5 / $grand * 100, 1) : 0.0,
            'largest' => $named[0] ?? null,
        ];

        return ['rows' => $rows, 'totals' => $totals, 'kpi' => $kpi, 'buckets' => $buckets, 'side' => $side, 'compare' => $comparePeriod];
    }

    /**
     * Counterparty x month matrix (second Excel sheet): the same buckets as the sparkline.
     *
     * @return array{buckets: array, rows: array<int, array{label: string, values: float[], total: float}>, totals: float[]}
     */
    public function monthlyMatrix(ReportFilters $f, string $side, ?array $listing = null): array
    {
        $listing = $listing ?? $this->listing($f, $side);
        $totals = array_fill(0, count($listing['buckets']), 0.0);
        $rows = [];

        foreach ($listing['rows'] as $r) {
            foreach ($r['spark'] as $i => $v) {
                $totals[$i] += $v;
            }

            $rows[] = ['label' => $r['label'], 'values' => $r['spark'], 'total' => $r['total']];
        }

        return ['buckets' => $listing['buckets'], 'rows' => $rows, 'totals' => array_map(fn($v) => round($v, 2), $totals), 'grand' => $listing['totals']['total']];
    }

    /**
     * Detail of one counterparty: KPI, periods, breakdowns and the latest documents.
     *
     * @param string $name counterparty name or NONE_KEY
     * @return array<string, mixed>
     */
    public function detail(ReportFilters $f, string $side, string $name, int $latest = 20): array
    {
        $side = self::normalizeSide($side);
        $isNone = $name === self::NONE_KEY;
        $nameValue = $isNone ? '' : $name;

        $scope = function (Builder $q) use ($nameValue) {
            return $q->whereRaw($this->nameExpr() . ' = ?', [$nameValue]);
        };

        // Period table: filters periodicity (default monthly), every bucket present
        $periodExpr = PeriodBuckets::sqlExpression($f->periodicity, 'fin_entries.entry_date');
        $byPeriod = $scope($this->base($f, $side))
            ->selectRaw("$periodExpr as bucket, SUM(fin_entries.amount_base) as total, COUNT(*) as entries, " . $this->docsExpr() . ' as docs')
            ->groupBy(DB::raw($periodExpr))
            ->get()
            ->keyBy('bucket');

        $periods = [];
        $prev = null;

        foreach ($f->buckets() as $b) {
            $row = $byPeriod[$b['key']] ?? null;
            $total = round((float)($row->total ?? 0), 2);
            $periods[] = [
                'key' => $b['key'],
                'label' => $b['label'],
                'total' => $total,
                'docs' => (int)($row->docs ?? 0),
                'change_pct' => $prev === null ? null : ReportBuilder::changePct($total, $prev),
            ];
            $prev = $total;
        }

        $total = round(array_sum(array_column($periods, 'total')), 2);
        $docs = (int)array_sum(array_column($periods, 'docs'));
        $sideTotal = round((float)$this->base($f, $side)->sum('fin_entries.amount_base'), 2);

        $kpi = [
            'total' => $total,
            'docs' => $docs,
            'avg' => $docs > 0 ? round($total / $docs, 2) : 0.0,
            'share' => $sideTotal > 0 ? round($total / $sideTotal * 100, 1) : 0.0,
            'last_date' => $scope($this->base($f, $side))->max('fin_entries.entry_date'),
        ];

        $comparePeriod = $f->comparePeriod();

        if ($comparePeriod) {
            $kpi['previous'] = round((float)$scope($this->base($f, $side, $comparePeriod['from'], $comparePeriod['to']))->sum('fin_entries.amount_base'), 2);
            $kpi['change_pct'] = ReportBuilder::changePct($total, $kpi['previous']);
        }

        // Breakdowns
        $breakdowns = [];
        $definitions = [
            'accounts' => ["CONCAT(fin_accounts.code, ' ', fin_accounts.name)", 'fin_accounts.id', null],
            'entities' => ['fin_entities.name', 'fin_entities.id', ['fin_entities', 'fin_entities.id', 'fin_entries.entity_id']],
            'products' => ['fin_dimensions.name', 'fin_dimensions.id', ['fin_dimensions', 'fin_dimensions.id', 'fin_entries.product_group_id']],
            'regions' => ['fin_dimensions.name', 'fin_dimensions.id', ['fin_dimensions', 'fin_dimensions.id', 'fin_entries.region_id']],
        ];

        foreach ($definitions as $key => [$labelExpr, $groupCol, $join]) {
            $q = $scope($this->base($f, $side));

            if ($join) {
                $q->leftJoin($join[0], $join[1], '=', $join[2]);
            }

            $rows = $q->selectRaw("$labelExpr as item_label, SUM(fin_entries.amount_base) as total, COUNT(*) as entries")
                ->groupBy(DB::raw($groupCol), DB::raw($labelExpr))
                ->orderByDesc('total')
                ->limit(12)
                ->get()
                ->map(fn($r) => [
                    'label' => $r->item_label ?: __('databackd-reports-common.dimension_none'),
                    'total' => round((float)$r->total, 2),
                    'entries' => (int)$r->entries,
                    'share' => $total > 0 ? round((float)$r->total / $total * 100, 1) : 0.0,
                ])
                ->all();

            // A breakdown with a single "no value" row says nothing
            $meaningful = array_filter($rows, fn($r) => $r['label'] !== __('databackd-reports-common.dimension_none'));
            $breakdowns[$key] = $meaningful ? $rows : [];
        }

        // Latest documents
        $latestRows = $scope($this->base($f, $side))
            ->leftJoin('fin_entities as le', 'le.id', '=', 'fin_entries.entity_id')
            ->orderByDesc('fin_entries.entry_date')
            ->orderByDesc('fin_entries.id')
            ->limit($latest)
            ->get([
                'fin_entries.entry_date', 'fin_entries.document_no', 'fin_entries.description', 'fin_entries.amount_base',
                'fin_accounts.code as account_code', 'fin_accounts.name as account_name', 'fin_accounts.type as account_type',
                'le.name as entity_name',
            ])
            ->map(fn($r) => [
                'date' => Carbon::parse($r->entry_date),
                'document_no' => $r->document_no,
                'description' => $r->description,
                'account' => $r->account_code . ' ' . $r->account_name,
                'type' => $r->account_type,
                'entity' => $r->entity_name,
                'amount' => round((float)$r->amount_base, 2),
            ])
            ->all();

        return [
            'name' => $nameValue,
            'label' => $isNone ? __('databackd-reports-counterparties.no_counterparty') : $name,
            'side' => $side,
            'kpi' => $kpi,
            'periods' => $periods,
            'breakdowns' => $breakdowns,
            'latest' => $latestRows,
            'compare' => $comparePeriod,
        ];
    }

    /**
     * Direction of a series: the second half against the first half.
     *
     * @return array{0: string, 1: ?float} [up|down|flat, change %]
     */
    public static function trend(array $values): array
    {
        $n = count($values);

        if ($n < 2) {
            return ['flat', null];
        }

        $half = intdiv($n, 2);
        $first = array_sum(array_slice($values, 0, $half));
        $second = array_sum(array_slice($values, $n - $half));
        $pct = ReportBuilder::changePct($second, $first);

        if ($pct === null) {
            return [$second > 0 ? 'up' : 'flat', null];
        }

        return [abs($pct) < 2 ? 'flat' : ($pct > 0 ? 'up' : 'down'), $pct];
    }

    /**
     * Inline SVG sparkline (no library): a polyline of the values, green when rising, red when falling.
     */
    public static function sparkline(array $values, string $trend = 'flat', int $width = 110, int $height = 28): string
    {
        $color = ['up' => '#1d8a4e', 'down' => '#d3302f', 'flat' => '#8a97a6'][$trend] ?? '#8a97a6';
        $n = count($values);

        if ($n < 2) {
            return '';
        }

        $pad = 3;
        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $points = [];

        foreach ($values as $i => $v) {
            $x = $pad + ($width - 2 * $pad) * $i / ($n - 1);
            $y = $range > 0 ? $pad + ($height - 2 * $pad) * (1 - ($v - $min) / $range) : $height / 2;
            $points[] = round($x, 1) . ',' . round($y, 1);
        }

        $last = explode(',', end($points));

        return '<svg class="cp-spark" xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" role="img">'
            . '<polyline fill="none" stroke="' . $color . '" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" points="' . implode(' ', $points) . '"/>'
            . '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.2" fill="' . $color . '"/></svg>';
    }

}
