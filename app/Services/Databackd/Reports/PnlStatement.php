<?php

namespace App\Services\Databackd\Reports;

use App\Services\Databackd\FinancialDataService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Profit and loss statement (ОПР) by the Bulgarian accounting standards, built from fin_entries.
 *
 * One GROUP BY query (account x period bucket) feeds the whole statement; with a comparison period a second
 * query (per account) feeds the "previous period" figures. No console/session dependencies: the company is
 * passed to the constructor, so it is safe to use from PDF/e-mail jobs.
 *
 * build() returns:
 *   columns   [['key','label','from','to'], ...]   period columns (ReportFilters::buckets())
 *   rows      flat, ordered list of rows (see row() for the keys); account rows follow their group row
 *   kpis      net_sales / operating_profit / net_profit / margin  [key, value, previous, change_pct, kind]
 *   compare   null or ['from' => Carbon, 'to' => Carbon]
 *   tax       ['mode' => 'estimated'|'actual', 'rate' => 0.10]
 *   extra_codes  codes of the accounts that matched no rule (neither the synthetic account / NSS class nor the
 *                subtype) and were put in "other income" / "other expenses"
 *
 * Accounts are grouped by their synthetic account (the first three digits of the code, or of the code of the root
 * parent for an analytic account such as "703-1" or "A1" under 703), then by the NSS class (70x sales, 72x financial
 * income, 62x financial expenses), then by the subtype - see groupFor().
 *   dimension_note  null|revenue_only|expense_only|none: a dimension filter hides the other side (expenses have no
 *                   region/segment/product group/client; revenue has no cost center)
 *   has_data  bool
 *   currency  string
 *
 * Row keys: id, type (section|group|account|calc), style (normal|subtotal|result), level (0 section, 1 group/calc,
 * 2 account), parent (row id|null), code, name, account_ids (int[]), expandable, values [bucketKey => float],
 * total, pct (% of net sales), and with a comparison: prev, change, change_pct.
 */
class PnlStatement
{

    const TAX_RATE = 0.10;

    /** Standard accounts of the statement (kept for callers; the grouping itself is done by groupFor()). */
    const STANDARD_CODES = ['702', '703', '704', '709', '721', '601', '602', '603', '604', '605', '609', '621'];

    /** Synthetic expense account => group (60x by nature of expense; 606 taxes and fees is an "other" expense). */
    const EXPENSE_BY_SYNTHETIC = [
        '601' => 'materials', '602' => 'services', '603' => 'depreciation', '604' => 'payroll', '605' => 'social',
        '606' => 'other_expense', '609' => 'other_expense',
    ];

    /** Expense subtype => group, for accounts outside the standard classes. */
    const EXPENSE_BY_SUBTYPE = [
        'cogs' => 'materials', 'opex' => 'services', 'depreciation' => 'depreciation', 'payroll' => 'payroll',
        'interest' => 'fin_expense', 'other' => 'other_expense',
    ];

    /** @var array<int, array{code: string, parent: ?int}>|null chart of accounts of the company (for analytic accounts) */
    protected ?array $chart = null;

    protected int $companyId;
    protected FinancialDataService $data;

    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId ?? company()->id;
        $this->data = new FinancialDataService($this->companyId);
    }

    public function build(ReportFilters $f): array
    {
        $buckets = $f->buckets();
        $keys = array_column($buckets, 'key');
        $filters = $f->toServiceFilters();
        $compare = $f->comparePeriod();
        $hasPrev = $compare !== null;

        $current = $this->fetch($f->from, $f->to, $filters, $f->periodicity);
        $previous = $hasPrev ? $this->fetch($compare['from'], $compare['to'], $filters, null) : collect();

        // account id => [id, code, name, type, subtype, group, vec]
        $accounts = [];

        foreach ([[$current, false], [$previous, true]] as [$rows, $isPrev]) {
            foreach ($rows as $r) {
                if (!isset($accounts[$r->account_id])) {
                    [$groupId, $fallback] = $this->classify((int)$r->account_id, (string)$r->code, $r->type, $r->subtype);
                    $accounts[$r->account_id] = [
                        'id' => (int)$r->account_id, 'code' => (string)$r->code, 'name' => (string)$r->name,
                        'type' => $r->type, 'subtype' => $r->subtype,
                        'group' => $groupId, 'fallback' => $fallback,
                        'vec' => $this->vec($keys, $hasPrev),
                    ];
                }

                $amount = (float)$r->amount;
                $vec =& $accounts[$r->account_id]['vec'];

                if ($isPrev) {
                    $vec['p'] += $amount;
                } else {
                    $vec['t'] += $amount;

                    if (array_key_exists($r->bucket, $vec['v'])) {
                        $vec['v'][$r->bucket] += $amount;
                    }
                }

                unset($vec);
            }
        }

        // group id => accounts ordered by code
        $byGroup = [];
        foreach ($accounts as $account) {
            $byGroup[$account['group']][] = $account;
        }
        foreach ($byGroup as &$list) {
            usort($list, fn($a, $b) => strcmp($a['code'], $b['code']));
        }
        unset($list);

        $groupVec = function (string $group) use ($byGroup, $keys, $hasPrev) {
            $vec = $this->vec($keys, $hasPrev);
            foreach ($byGroup[$group] ?? [] as $account) {
                $vec = $this->add($vec, $account['vec']);
            }

            return $vec;
        };

        $v = [];
        foreach (['net_sales', 'other_income', 'materials', 'services', 'depreciation', 'payroll', 'social', 'other_expense', 'fin_income', 'fin_expense'] as $g) {
            $v[$g] = $groupVec($g);
        }

        $v['operating_revenue'] = $this->add($v['net_sales'], $v['other_income']);
        $v['operating_expense'] = $this->vec($keys, $hasPrev);
        foreach (['materials', 'services', 'depreciation', 'payroll', 'social', 'other_expense'] as $g) {
            $v['operating_expense'] = $this->add($v['operating_expense'], $v[$g]);
        }
        $v['operating_profit'] = $this->sub($v['operating_revenue'], $v['operating_expense']);
        $v['profit_before_tax'] = $this->sub($this->add($v['operating_profit'], $v['fin_income']), $v['fin_expense']);

        $taxActual = !empty($byGroup['tax']);
        $v['tax'] = $taxActual ? $groupVec('tax') : $this->estimatedTax($v['profit_before_tax'], $keys, $hasPrev);
        $v['net_profit'] = $this->sub($v['profit_before_tax'], $v['tax']);

        $baseTotal = round($v['net_sales']['t'], 2);
        $rows = [];

        $section = function (string $id) use (&$rows) {
            $rows[] = ['id' => 'sec_' . $id, 'type' => 'section', 'style' => 'normal', 'level' => 0, 'parent' => null,
                'code' => '', 'name' => __('databackd-reports-pnl.sections.' . $id), 'account_ids' => [], 'expandable' => false,
                'values' => [], 'total' => 0.0, 'pct' => null];
        };

        $group = function (string $id, string $nameKey, array $defaultCodes) use (&$rows, $byGroup, $v, $baseTotal, $hasPrev) {
            $accountsOfGroup = $byGroup[$id] ?? [];
            $codes = $accountsOfGroup ? array_column($accountsOfGroup, 'code') : $defaultCodes;
            $expandable = count($accountsOfGroup) > 1;

            $rows[] = $this->makeRow($id, 'group', 'normal', 1, null, $this->codeLabel($codes), __('databackd-reports-pnl.rows.' . $nameKey),
                array_column($accountsOfGroup, 'id'), $expandable, $v[$id], $baseTotal, $hasPrev);

            if ($expandable) {
                foreach ($accountsOfGroup as $account) {
                    $rows[] = $this->makeRow('acc_' . $account['id'], 'account', 'normal', 2, $id, $account['code'], $account['name'],
                        [$account['id']], false, $account['vec'], $baseTotal, $hasPrev);
                }
            }
        };

        $calc = function (string $id, string $nameKey, string $style) use (&$rows, $v, $baseTotal, $hasPrev) {
            $rows[] = $this->makeRow($id, 'calc', $style, 1, null, '', __('databackd-reports-pnl.rows.' . $nameKey), [], false, $v[$id], $baseTotal, $hasPrev);
        };

        $section('revenue');
        $group('net_sales', 'net_sales', ['702', '703', '704']);
        $group('other_income', 'other_income', ['709']);
        $calc('operating_revenue', 'operating_revenue', 'subtotal');

        $section('expense');
        $group('materials', 'materials', ['601']);
        $group('services', 'services', ['602']);
        $group('depreciation', 'depreciation', ['603']);
        $group('payroll', 'payroll', ['604']);
        $group('social', 'social', ['605']);
        $group('other_expense', 'other_expense', ['609']);
        $calc('operating_expense', 'operating_expense', 'subtotal');
        $calc('operating_profit', 'operating_profit', 'result');

        $section('financial');
        $group('fin_income', 'fin_income', ['721']);
        $group('fin_expense', 'fin_expense', ['621']);
        $calc('profit_before_tax', 'profit_before_tax', 'result');

        if ($taxActual) {
            $group('tax', 'tax_actual', []);
        } else {
            $rows[] = $this->makeRow('tax', 'calc', 'normal', 1, null, '', __('databackd-reports-pnl.rows.tax_estimated'), [], false, $v['tax'], $baseTotal, $hasPrev);
        }

        $calc('net_profit', 'net_profit', 'result');

        $margin = fn(array $profit, array $sales, string $k) => abs($sales[$k]) > 0.004 ? round($profit[$k] / $sales[$k] * 100, 1) : null;
        $marginTotal = $margin($v['net_profit'], $v['net_sales'], 't');
        $marginPrev = $hasPrev ? $margin($v['net_profit'], $v['net_sales'], 'p') : null;

        $kpi = function (string $key, ?float $value, ?float $prev, string $kind) use ($hasPrev) {
            return [
                'key' => $key, 'value' => $value, 'previous' => $hasPrev ? $prev : null, 'kind' => $kind,
                'change_pct' => $hasPrev && $value !== null && $prev !== null ? ReportBuilder::changePct($value, $prev) : null,
                'change_pp' => $kind === 'pct' && $hasPrev && $value !== null && $prev !== null ? round($value - $prev, 1) : null,
            ];
        };

        $kpis = [
            $kpi('net_sales', round($v['net_sales']['t'], 2), $hasPrev ? round($v['net_sales']['p'], 2) : null, 'money'),
            $kpi('operating_profit', round($v['operating_profit']['t'], 2), $hasPrev ? round($v['operating_profit']['p'], 2) : null, 'money'),
            $kpi('net_profit', round($v['net_profit']['t'], 2), $hasPrev ? round($v['net_profit']['p'], 2) : null, 'money'),
            $kpi('margin', $marginTotal, $marginPrev, 'pct'),
        ];

        return [
            'columns' => $buckets,
            'rows' => $rows,
            'kpis' => $kpis,
            'compare' => $compare,
            'tax' => ['mode' => $taxActual ? 'actual' : 'estimated', 'rate' => self::TAX_RATE],
            'extra_codes' => array_values(array_unique(array_column(array_filter($accounts, fn($a) => $a['fallback']), 'code'))),
            'dimension_note' => $this->dimensionNote($f),
            'has_data' => $current->isNotEmpty(),
            'currency' => $this->currency(),
        ];
    }

    /**
     * Review A: currency of the amounts (fin_entries.amount_base = the company currency). Read by company_id, not from the
     * session company, so a PDF / e-mail job in the console gets the currency of the company of the report too.
     */
    protected function currency(): string
    {
        $code = \Illuminate\Support\Facades\DB::table('companies as c')
            ->join('currencies as cu', 'cu.id', '=', 'c.currency_id')
            ->where('c.id', $this->companyId)
            ->value('cu.currency_code');

        return strtoupper(trim((string)($code ?: 'EUR')));
    }

    /**
     * Revenue entries carry region / segment / product group / client, expenses carry the cost center only, so such a
     * filter leaves out the other side of the statement.
     */
    protected function dimensionNote(ReportFilters $f): ?string
    {
        $revenueSide = $f->regionId || $f->clientSegmentId || $f->productGroupId || $f->clientId;
        $expenseSide = (bool)$f->costCenterId;

        if ($revenueSide && $expenseSide) {
            return 'none';
        }

        return $revenueSide ? 'revenue_only' : ($expenseSide ? 'expense_only' : null);
    }

    /**
     * Account x bucket totals of revenue/expense flows. $periodicity null = one bucket 'all' (comparison period).
     */
    protected function fetch(Carbon $from, Carbon $to, array $filters, ?string $periodicity): Collection
    {
        $bucket = $periodicity ? PeriodBuckets::sqlExpression($periodicity, 'fin_entries.entry_date') : "'all'";

        return $this->data->query($filters)
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->where('fin_entries.kind', 'flow')
            ->whereIn('fin_accounts.type', ['revenue', 'expense'])
            ->whereBetween('fin_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("fin_accounts.id as account_id, fin_accounts.code as code, fin_accounts.name as name, fin_accounts.type as type, fin_accounts.subtype as subtype, $bucket as bucket, SUM(fin_entries.amount_base) as amount")
            ->groupByRaw("fin_accounts.id, fin_accounts.code, fin_accounts.name, fin_accounts.type, fin_accounts.subtype, $bucket")
            ->orderBy('fin_accounts.code')
            ->get();
    }

    /**
     * Statement group of an account (see the class description) and whether no rule matched (fallback "other").
     *
     * @return array{0: string, 1: bool}
     */
    protected function classify(int $accountId, string $code, string $type, ?string $subtype): array
    {
        $synthetic = $this->syntheticCode($accountId, $code);

        return self::groupFor($synthetic, $type, $subtype);
    }

    /**
     * Group by the synthetic account code (3 digits or '') of an account.
     *
     * @return array{0: string, 1: bool} [group, fallback]
     */
    public static function groupFor(string $synthetic, string $type, ?string $subtype): array
    {
        $class = substr($synthetic, 0, 2);

        if ($type === 'revenue') {
            if ($class === '70') {
                return [$synthetic === '709' ? 'other_income' : 'net_sales', false];
            }

            if ($class === '72' || $subtype === 'interest') {
                return ['fin_income', false];
            }

            // 71x / 74x / 75x and accounts with the subtype "other" are other operating income
            return ['other_income', !in_array($class, ['71', '74', '75'], true) && $subtype !== 'other'];
        }

        if ($subtype === 'tax') {
            return ['tax', false];
        }

        if (isset(self::EXPENSE_BY_SYNTHETIC[$synthetic])) {
            return [self::EXPENSE_BY_SYNTHETIC[$synthetic], false];
        }

        if ($class === '62') {
            return ['fin_expense', false];
        }

        if (isset(self::EXPENSE_BY_SUBTYPE[(string)$subtype])) {
            return [self::EXPENSE_BY_SUBTYPE[(string)$subtype], false];
        }

        return ['other_expense', true];
    }

    /**
     * Synthetic account of an account: the first three digits of its code, otherwise of the code of its nearest
     * parent that has them (analytic accounts like "A1" under 703); '' when there is none.
     */
    protected function syntheticCode(int $accountId, string $code): string
    {
        if (preg_match('/^(\d{3})/', $code, $m)) {
            return $m[1];
        }

        if ($this->chart === null) {
            $this->chart = [];

            foreach (\Illuminate\Support\Facades\DB::table('fin_accounts')->where('company_id', $this->companyId)->get(['id', 'code', 'parent_id']) as $a) {
                $this->chart[(int)$a->id] = ['code' => (string)$a->code, 'parent' => $a->parent_id ? (int)$a->parent_id : null];
            }
        }

        $seen = [];
        $id = $this->chart[$accountId]['parent'] ?? null;

        while ($id && !isset($seen[$id]) && isset($this->chart[$id])) {
            $seen[$id] = true;

            if (preg_match('/^(\d{3})/', $this->chart[$id]['code'], $m)) {
                return $m[1];
            }

            $id = $this->chart[$id]['parent'];
        }

        return '';
    }

    /**
     * Estimated corporate tax: TAX_RATE x profit before tax of the period (zero for a loss). The columns get the same
     * share of their own profit (a loss month gets a negative tax), so the columns add up to the total.
     */
    protected function estimatedTax(array $pbt, array $keys, bool $hasPrev): array
    {
        $tax = $this->vec($keys, $hasPrev);

        if ($pbt['t'] > 0) {
            foreach ($keys as $k) {
                $tax['v'][$k] = round(self::TAX_RATE * $pbt['v'][$k], 2);
            }

            $tax['t'] = round(self::TAX_RATE * $pbt['t'], 2);
            $diff = round($tax['t'] - array_sum($tax['v']), 2);

            if (abs($diff) >= 0.01 && $keys) {
                // put the cent rounding difference on the most profitable column
                $abs = array_map('abs', $pbt['v']);
                $k = array_search(max($abs), $abs, true);
                $tax['v'][$k] = round($tax['v'][$k] + $diff, 2);
            }
        }

        if ($hasPrev) {
            $tax['p'] = ($pbt['p'] ?? 0) > 0 ? round(self::TAX_RATE * $pbt['p'], 2) : 0.0;
        }

        return $tax;
    }

    protected function makeRow(string $id, string $type, string $style, int $level, ?string $parent, string $code, string $name, array $accountIds, bool $expandable, array $vec, float $baseTotal, bool $hasPrev): array
    {
        $values = array_map(fn($x) => round($x, 2), $vec['v']);
        $total = round($vec['t'], 2);

        $row = [
            'id' => $id, 'type' => $type, 'style' => $style, 'level' => $level, 'parent' => $parent,
            'code' => $code, 'name' => $name, 'account_ids' => array_map('intval', $accountIds), 'expandable' => $expandable,
            'values' => $values, 'total' => $total,
            'pct' => abs($baseTotal) > 0.004 ? round($total / $baseTotal * 100, 1) : null,
        ];

        if ($hasPrev) {
            $prev = round((float)$vec['p'], 2);
            $row['prev'] = $prev;
            $row['change'] = round($total - $prev, 2);
            $row['change_pct'] = ReportBuilder::changePct($total, $prev);
        }

        return $row;
    }

    /** "702–704" for a run of consecutive codes, otherwise "601, 603". */
    protected function codeLabel(array $codes): string
    {
        $codes = array_values(array_unique($codes));
        sort($codes);

        if (count($codes) >= 3 && ctype_digit(implode('', $codes))) {
            $consecutive = true;
            for ($i = 1; $i < count($codes); $i++) {
                if ((int)$codes[$i] !== (int)$codes[$i - 1] + 1) {
                    $consecutive = false;
                    break;
                }
            }

            if ($consecutive) {
                return $codes[0] . '–' . end($codes);
            }
        }

        return count($codes) > 4 ? $codes[0] . ' +' . (count($codes) - 1) : implode(', ', $codes);
    }

    /** Zero vector: per bucket values, total, previous period total (null without comparison). */
    protected function vec(array $keys, bool $hasPrev): array
    {
        return ['v' => array_fill_keys($keys, 0.0), 't' => 0.0, 'p' => $hasPrev ? 0.0 : null];
    }

    protected function add(array $a, array $b): array
    {
        return $this->combine($a, $b, 1);
    }

    protected function sub(array $a, array $b): array
    {
        return $this->combine($a, $b, -1);
    }

    protected function combine(array $a, array $b, int $sign): array
    {
        foreach ($a['v'] as $k => $x) {
            $a['v'][$k] = $x + $sign * ($b['v'][$k] ?? 0);
        }

        $a['t'] += $sign * $b['t'];

        if ($a['p'] !== null) {
            $a['p'] += $sign * ($b['p'] ?? 0);
        }

        return $a;
    }

}
