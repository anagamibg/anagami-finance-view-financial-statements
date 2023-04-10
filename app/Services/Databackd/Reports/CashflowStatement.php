<?php

namespace App\Services\Databackd\Reports;

use App\Models\Databackd\FinAccount;
use App\Services\Databackd\FinancialDataService;
use App\Services\Databackd\Gaps\Statutory\NssMapping;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cash flow statement by the direct method (NSS 7 layout: operating, investing and financing activities), derived from
 * the data AFV has: revenue / expense turnover (`kind = flow`) and month-end balances (`kind = balance`).
 *
 * The ledger has no cash journal, so every line is "accrual flow corrected with the change of the related balance":
 *   operating  receipts from customers   = sales and other operating income        - change of receivables
 *              payments to suppliers     = -(materials + services + other expenses) + change of suppliers
 *                                           - change of inventories and prepaid expenses
 *              payments to personnel     = -(salaries + social security)            + change of the payroll liabilities
 *              taxes paid                = -(profit tax booked)                     + change of the tax liabilities
 *              other operating           = change of provisions, deferred income and other short-term liabilities
 *   investing  non-current assets        = -(change of the net book value of non-current assets + depreciation)
 *                                           (depreciation is not a cash payment: it is taken out of the operating lines)
 *              interest and financial income received (72x), change of short-term investments
 *   financing  loans received / repaid   = change of the bank loans and other long-term liabilities
 *              capital                   = change of the capital and reserves (10x, 11x)
 *              interest and other financial expenses paid (62x)
 * The cash accounts are the accounts that NssMapping places on the "cash" rows of the balance sheet (501, 503, other
 * 50x and analytic accounts of them, subtype "cash"). Opening balance of a bucket = cash at the end of the previous
 * bucket (the day before its start), closing = cash at the end of the bucket.
 *
 * "Other movements" closes the statement: closing - (opening + operating + investing + financing). It holds what the
 * data cannot explain: the change of the financial result accounts (12x) beyond the booked result (dividends,
 * corrections of previous years), accounts without a balance sheet row and missing balances. It is the check of the
 * statement against the movement of the cash accounts (50x).
 *
 * Balances are split by legal entity only; dimension filters (region, segment, ...) and the client filter apply to
 * the turnover but not to the balances (notes.dimensions_ignored).
 *
 * Works without a logged in user (console, PDF mail): the tenant is passed to the constructor.
 */
class CashflowStatement
{

    /** Activity => line keys, in print order. */
    const ACTIVITIES = [
        'operating' => ['customers', 'suppliers', 'personnel', 'taxes', 'other_operating'],
        'investing' => ['fixed_assets', 'investments', 'fin_income'],
        'financing' => ['loans', 'equity', 'interest'],
    ];

    /** PnlStatement group of a revenue / expense account => cash flow line. */
    const FLOW_LINES = [
        'net_sales' => 'customers', 'other_income' => 'customers', 'fin_income' => 'fin_income',
        'materials' => 'suppliers', 'services' => 'suppliers', 'other_expense' => 'suppliers',
        'payroll' => 'personnel', 'social' => 'personnel', 'depreciation' => 'fixed_assets',
        'fin_expense' => 'interest', 'tax' => 'taxes',
    ];

    /** Balance sheet row (NssMapping key prefix) => cash flow line; rows not listed stay in "other movements". */
    const BALANCE_LINES = [
        'asset.U' => 'equity',
        'asset.A.' => 'fixed_assets',
        'asset.B.I.' => 'suppliers',
        'asset.B.II.' => 'customers',
        'asset.B.III' => 'investments',
        'asset.B.IV.' => 'cash',
        'asset.C' => 'suppliers',
        'liab.A.I' => 'equity',
        'liab.A.P' => 'equity',
        'liab.A.R' => 'equity',
        'liab.A.II' => 'equity',
        'liab.B' => 'other_operating',
        'liab.C.I.' => 'loans',
        'liab.C.II.1' => 'loans',
        'liab.C.II.2' => 'suppliers',
        'liab.C.II.3' => 'personnel',
        'liab.C.II.4' => 'taxes',
        'liab.C.II.5' => 'other_operating',
        'liab.D' => 'other_operating',
    ];

    protected int $companyId;
    protected FinancialDataService $data;

    /** @var array<int, array{code: string, name: string, type: string, subtype: ?string, line: ?string, contra: bool}>|null */
    protected ?array $chart = null;

    /** @var array<string, array<int, float>> balances (account id => amount) by effective balance date + filter */
    protected array $balanceCache = [];

    /** @var array<string, string[]> distinct balance dates (ascending) by filter */
    protected array $balanceDates = [];

    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId ?? company()->id;
        $this->data = new FinancialDataService($this->companyId);
    }

    /**
     * Builds the statement.
     *
     * @return array{
     *   columns: array<int, array{key: string, label: string, from: Carbon, to: Carbon}>,
     *   rows: array<int, array{key: string, label: string, kind: string, values: array<string, float>, total: float, children: array}>,
     *   kpi: array{opening: float, operating: float, investing: float, financing: float, net: float, other: float, closing: float},
     *   chart: array{labels: string[], operating: float[], investing: float[], financing: float[], closing: float[]},
     *   notes: array{dimensions_ignored: bool, no_balances: bool, cash_accounts: string[]},
     *   has_data: bool,
     *   compare: ?array{period: array{from: Carbon, to: Carbon}, kpi: array, change: array<string, ?float>}
     * }
     *
     * Row kinds: opening, operating, investing, financing, net, other, closing. Children (the three activities only)
     * are the lines [key, label, code ('' - the lines are not single accounts), kind => 'line', values, total].
     */
    public function build(ReportFilters $f): array
    {
        $result = $this->buildRange($f);

        $result['compare'] = null;

        if ($f->compare !== 'none' && ($range = $f->comparePeriod())) {
            $other = $this->buildRange($f->withRange($range['from'], $range['to']));
            $change = [];

            foreach (['opening', 'operating', 'investing', 'financing', 'net', 'closing'] as $key) {
                $change[$key] = ReportBuilder::changePct($result['kpi'][$key], $other['kpi'][$key]);
            }

            $result['compare'] = ['period' => $range, 'kpi' => $other['kpi'], 'change' => $change];
        }

        return $result;
    }

    protected function buildRange(ReportFilters $f): array
    {
        $columns = array_map(fn($b) => ['key' => $b['key'], 'label' => $b['label'], 'from' => $b['from'], 'to' => $b['to']], $f->buckets());
        $keys = array_column($columns, 'key');
        $zero = array_fill_keys($keys, 0.0);
        $chart = $this->chart();
        $balanceFilter = $f->entityIds ? ['entity_ids' => $f->entityIds] : [];

        $lines = [];
        foreach (self::ACTIVITIES as $activityLines) {
            foreach ($activityLines as $line) {
                $lines[$line] = $zero;
            }
        }

        // 1. turnover of the buckets (revenue +, expense -) on the lines of their statement group
        foreach ($this->flowsByBucket($f) as $accountId => $byBucket) {
            $account = $chart[$accountId] ?? null;

            if (!$account || !$account['line']) {
                continue;
            }

            $sign = $account['type'] === 'revenue' ? 1 : -1;

            foreach ($byBucket as $bucket => $amount) {
                if (array_key_exists($bucket, $zero)) {
                    $lines[$account['line']][$bucket] += $sign * $amount;
                }
            }
        }

        // 2. change of the balances between the start and the end of every bucket; cash = opening / closing balance
        $opening = $closing = $zero;
        $previousEnd = null;

        foreach ($columns as $i => $col) {
            $start = $i === 0 ? $this->balancesOn($col['from']->copy()->subDay(), $balanceFilter) : $previousEnd;
            $end = $this->balancesOn($col['to'], $balanceFilter);
            $previousEnd = $end;

            foreach (array_unique(array_merge(array_keys($start), array_keys($end))) as $accountId) {
                $account = $chart[$accountId] ?? null;

                // review A: a revenue / expense account with balance entries (e.g. an opening balance) is already in the
                // turnover of step 1 - its balance change would count the same amount twice
                if (!$account || !$account['line'] || in_array($account['type'], ['revenue', 'expense'], true)) {
                    continue;
                }

                $before = $start[$accountId] ?? 0.0;
                $after = $end[$accountId] ?? 0.0;

                if ($account['line'] === 'cash') {
                    $opening[$col['key']] += $before;
                    $closing[$col['key']] += $after;
                    continue;
                }

                // more assets = cash spent, more liabilities / equity = cash received
                $lines[$account['line']][$col['key']] += ($account['type'] === 'asset' ? -1 : 1) * ($after - $before);
            }
        }

        // 3. rows
        $activityRows = [];
        $activityValues = [];

        foreach (self::ACTIVITIES as $activity => $activityLines) {
            $children = [];
            $sum = $zero;

            foreach ($activityLines as $line) {
                $values = array_map(fn($v) => round($v, 2) + 0.0, $lines[$line]);
                $total = round(array_sum($values), 2);

                foreach ($keys as $k) {
                    $sum[$k] += $values[$k];
                }

                // a line without any amount in the whole range is left out (e.g. no investments, no tax accounts)
                if (abs($total) < 0.005 && !array_filter($values, fn($v) => abs($v) >= 0.005)) {
                    continue;
                }

                $children[] = [
                    'key' => $line, 'label' => __('databackd-reports-cashflow.lines.' . $line), 'code' => '', 'kind' => 'line',
                    'values' => $values, 'total' => $total,
                ];
            }

            $activityValues[$activity] = array_map(fn($v) => round($v, 2) + 0.0, $sum);
            $activityRows[$activity] = $children;
        }

        $net = $other = $zero;

        foreach ($keys as $k) {
            $opening[$k] = round($opening[$k], 2);
            $closing[$k] = round($closing[$k], 2);
            $net[$k] = round($activityValues['operating'][$k] + $activityValues['investing'][$k] + $activityValues['financing'][$k], 2) + 0.0;
            $other[$k] = round($closing[$k] - $opening[$k] - $net[$k], 2) + 0.0;
        }

        $first = $keys[0] ?? null;
        $last = $keys ? $keys[count($keys) - 1] : null;
        $sumOf = fn(array $values) => round(array_sum($values), 2);

        $kpi = [
            'opening' => $first !== null ? $opening[$first] : 0.0,
            'operating' => $sumOf($activityValues['operating']),
            'investing' => $sumOf($activityValues['investing']),
            'financing' => $sumOf($activityValues['financing']),
            'net' => $sumOf($net),
            'other' => $sumOf($other),
            'closing' => $last !== null ? $closing[$last] : 0.0,
        ];

        $row = fn(string $key, array $values, float $total, array $children = []) => [
            'key' => $key,
            'label' => __('databackd-reports-cashflow.rows.' . $key),
            'kind' => $key,
            'values' => $values,
            'total' => $total,
            'children' => $children,
        ];

        $rows = [$row('opening', $opening, $kpi['opening'])];

        foreach (array_keys(self::ACTIVITIES) as $activity) {
            $rows[] = $row($activity, $activityValues[$activity], $kpi[$activity], $activityRows[$activity]);
        }

        $rows[] = $row('net', $net, $kpi['net']);
        $rows[] = $row('other', $other, $kpi['other']);
        $rows[] = $row('closing', $closing, $kpi['closing']);

        $hasBalances = $this->balanceDates($balanceFilter) !== [];
        $hasFlows = (bool)array_filter($lines, fn($values) => array_filter($values, fn($v) => abs($v) >= 0.005));

        return [
            'columns' => $columns,
            'rows' => $rows,
            'kpi' => $kpi,
            'chart' => [
                'labels' => array_column($columns, 'label'),
                'operating' => array_values($activityValues['operating']),
                'investing' => array_values($activityValues['investing']),
                'financing' => array_values($activityValues['financing']),
                'closing' => array_values($closing),
            ],
            'notes' => [
                'dimensions_ignored' => (bool)array_filter([$f->regionId, $f->clientSegmentId, $f->productGroupId, $f->costCenterId, $f->clientId]),
                'no_balances' => !$hasBalances,
                'cash_accounts' => $this->cashCodes(),
            ],
            'has_data' => $hasBalances || $hasFlows,
        ];
    }

    /**
     * Revenue / expense turnover grouped in SQL by account and period bucket.
     *
     * @return array<int, array<string, float>> account id => bucket => amount
     */
    protected function flowsByBucket(ReportFilters $f): array
    {
        $bucket = PeriodBuckets::sqlExpression($f->periodicity, 'fin_entries.entry_date');

        $rows = $this->data->query($f->toServiceFilters())
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->where('fin_entries.kind', 'flow')
            ->whereIn('fin_accounts.type', ['revenue', 'expense'])
            ->whereBetween('fin_entries.entry_date', [$f->from->toDateString(), $f->to->toDateString()])
            ->groupBy('fin_entries.account_id')
            ->groupByRaw($bucket)
            ->selectRaw("fin_entries.account_id as account_id, $bucket as bucket, SUM(fin_entries.amount_base) as total")
            ->get();

        $data = [];

        foreach ($rows as $r) {
            $data[(int)$r->account_id][(string)$r->bucket] = (float)$r->total;
        }

        return $data;
    }

    /**
     * Balances of all balance sheet accounts at the end of $date (latest balance entry per legal entity and account on
     * or before the date; contra accounts negative). Balances exist only on some dates (month ends), so the date is
     * mapped to the latest balance date on or before it and the result is cached per such date.
     *
     * @return array<int, float> account id => balance
     */
    protected function balancesOn(Carbon $date, array $filter): array
    {
        $day = $date->toDateString();
        $effective = null;

        foreach ($this->balanceDates($filter) as $d) {
            if ($d > $day) {
                break;
            }

            $effective = $d;
        }

        if ($effective === null) {
            return [];
        }

        $cacheKey = $effective . '|' . json_encode($filter);

        if (isset($this->balanceCache[$cacheKey])) {
            return $this->balanceCache[$cacheKey];
        }

        $latest = DB::table('fin_entries')
            ->where('company_id', $this->companyId)
            ->where('kind', 'balance')
            ->where('entry_date', '<=', $effective)
            ->groupBy('entity_id', 'account_id')
            ->selectRaw('entity_id, account_id, MAX(entry_date) as last_date');

        $rows = $this->data->query($filter)
            ->joinSub($latest, 'lb', function ($join) {
                $join->whereRaw('lb.entity_id <=> fin_entries.entity_id')
                    ->on('lb.account_id', '=', 'fin_entries.account_id')
                    ->on('lb.last_date', '=', 'fin_entries.entry_date');
            })
            ->where('fin_entries.kind', 'balance')
            ->groupBy('fin_entries.account_id')
            ->selectRaw('fin_entries.account_id as account_id, SUM(fin_entries.amount_base) as total')
            ->pluck('total', 'account_id');

        $chart = $this->chart();
        $out = [];

        foreach ($rows as $accountId => $total) {
            $out[(int)$accountId] = ($chart[(int)$accountId]['contra'] ?? false) ? -(float)$total : (float)$total;
        }

        return $this->balanceCache[$cacheKey] = $out;
    }

    /** @return string[] ascending distinct dates of the balance entries of the filter */
    protected function balanceDates(array $filter): array
    {
        $key = json_encode($filter);

        return $this->balanceDates[$key] ??= $this->data->query($filter)
            ->where('fin_entries.kind', 'balance')
            ->distinct()
            ->orderBy('fin_entries.entry_date')
            ->pluck('fin_entries.entry_date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->all();
    }

    /**
     * Chart of accounts with the cash flow line of every account (null = not classified, stays in "other movements").
     *
     * @return array<int, array{code: string, name: string, type: string, subtype: ?string, line: ?string, contra: bool}>
     */
    protected function chart(): array
    {
        if ($this->chart !== null) {
            return $this->chart;
        }

        $all = DB::table('fin_accounts')->where('company_id', $this->companyId)->get(['id', 'code', 'name', 'type', 'subtype', 'parent_id'])->keyBy('id');
        $this->chart = [];
        // review A: analytic accounts of a contra account (2411 / "A1" under 241) are subtracted too
        $contra = FinAccount::contraMap($all);

        foreach ($all as $a) {
            $synthetic = $this->syntheticOf($a, $all);
            $line = null;

            if (in_array($a->type, ['revenue', 'expense'], true)) {
                $line = self::FLOW_LINES[PnlStatement::groupFor($synthetic, $a->type, $a->subtype)[0]] ?? null;
            }
            else {
                [$row] = NssMapping::balanceRow(['code' => (string)$a->code, 'type' => $a->type, 'subtype' => $a->subtype, 'synthetic' => $synthetic]);

                foreach (self::BALANCE_LINES as $prefix => $candidate) {
                    if ($row === $prefix || (str_ends_with($prefix, '.') && str_starts_with($row, $prefix))) {
                        $line = $candidate;
                        break;
                    }
                }
            }

            $this->chart[(int)$a->id] = [
                'code' => (string)$a->code, 'name' => (string)$a->name, 'type' => (string)$a->type, 'subtype' => $a->subtype,
                'line' => $line, 'contra' => $contra[(int)$a->id] ?? FinAccount::isContraCode((string)$a->code, $synthetic),
            ];
        }

        return $this->chart;
    }

    /** @return string[] codes of the cash accounts (for the notes) */
    protected function cashCodes(): array
    {
        $codes = array_column(array_filter($this->chart(), fn($a) => $a['line'] === 'cash'), 'code');
        sort($codes, SORT_STRING);

        return array_values($codes);
    }

    /** Synthetic account (3 digits) of an account, from its own code or the nearest parent with one; '' if none. */
    protected function syntheticOf(object $account, \Illuminate\Support\Collection $all): string
    {
        $seen = [];

        for ($a = $account; $a && !isset($seen[$a->id]); $a = $a->parent_id ? $all->get($a->parent_id) : null) {
            $seen[$a->id] = true;

            if (preg_match('/^(\d{3})/', (string)$a->code, $m)) {
                return $m[1];
            }
        }

        return '';
    }

}
