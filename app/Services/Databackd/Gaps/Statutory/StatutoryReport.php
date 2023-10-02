<?php

namespace App\Services\Databackd\Gaps\Statutory;

use App\Models\Company;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinConsolidationGroup;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Scopes\CompanyScope;
use App\Services\Databackd\Analytics\ConsolidationService;
use App\Services\Databackd\FinancialDataService;
use App\Services\Databackd\Reports\PnlStatement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Simplified annual financial statements by the Bulgarian accounting standard NSS 1 (F/R5 "compliance and
 * reporting"): balance sheet and income statement with a current and a previous year column, for one legal entity
 * or for a consolidation group.
 *
 * Sources (read only, nothing is written):
 *   - legal entity / whole company: FinancialDataService::balanceAt() on 31.12. for every balance sheet account and
 *     FinancialDataService::pnl() for the turnover of the calendar year;
 *   - consolidation group: ConsolidationService::run() for the calendar year (balances at 31.12., turnover for the
 *     year, after eliminations and adjustments) - the result format of the service is used as it is.
 * Accounts are placed on the rows by NssMapping.
 *
 * $scope: ['entity_id' => int] (legal entity), ['group_id' => int] (consolidation group) or [] (all entries of the
 * company, a plain sum without eliminations). Scope strings of the UI: "entity:<id>", "group:<id>" (parseScope()).
 *
 * Result of balanceSheet() / incomeStatement():
 * [
 *   'kind' => 'balance'|'income', 'year' => int, 'previous_year' => int, 'currency' => string,
 *   'dates' => ['current' => 'YYYY-12-31', 'previous' => 'YYYY-12-31'],     // balance sheet dates
 *   'rows' => [[                                                           // print order
 *       'key' => string, 'code' => string (as printed, e.g. "А.II.1"), 'name' => string,
 *       'current' => float|null, 'previous' => float|null (null on headings),
 *       'level' => 0 heading|1 section|2 group|3 line, 'type' => heading|subtotal|line|total|result|memo,
 *       'accounts' => string[] (codes of the chart accounts mapped to the row),
 *   ], ...],
 *   'unmapped' => [['code', 'name', 'current', 'previous'], ...],         // accounts without a row (in "Other")
 *   'notes' => string[],
 *   balance sheet only: 'totals' => ['assets' => ['current','previous'], 'liabilities' => [...], 'difference' => [...]],
 *                       'balanced' => bool, 'result_mode' => ['current' => included|added, 'previous' => ...]
 *   income statement only: 'tax_mode' => estimated|actual, 'values' => ['current' => [row key => float], 'previous' => [...]]
 * ]
 *
 * Equity: account 121 holds the closing financial result of the balance date. In the AFV data model it already
 * contains the booked result of the current year (the demo balance sheet balances without adding the P&L), so the
 * balance sheet splits 121 into "from previous years" (121 less the BOOKED result of the year: profit before tax when
 * the tax is only estimated) and "current year" (income statement C.5, after tax). When the data balances only if the
 * booked result is ADDED to 121, that is detected per column and used.
 *
 * Estimated profit tax (no account with the subtype "tax"): the income statement deducts it, so the balance sheet
 * accrues the same amount as a tax liability (row C.II.4) - the result of the year equals C.5 of the income
 * statement and the balance sheet still balances, without shifting the tax into the result of previous years.
 */
class StatutoryReport
{

    /** Same estimate as the P&L screen when the chart has no profit tax account. */
    const TAX_RATE = PnlStatement::TAX_RATE;

    /** Max difference (EUR) between assets and liabilities + equity that is still treated as balanced. */
    const TOLERANCE = 0.5;

    protected int $companyId;
    protected FinancialDataService $data;
    protected ?array $accountsCache = null;
    protected array $cache = [];

    /** audit 2026-10-30 (G11): consolidation groups belong to databackd_analytics - without it only legal entities are offered */
    protected bool $withGroups;

    public function __construct(?int $companyId = null, bool $withGroups = true)
    {
        $this->companyId = $companyId ?? company()->id;
        $this->withGroups = $withGroups;
        $this->data = new FinancialDataService($this->companyId);
    }

    // ---------------------------------------------------------------------------------------------
    // Scope and years
    // ---------------------------------------------------------------------------------------------

    /**
     * Options of the "legal entity or group" selector.
     *
     * @return array{entities: array<int, array{value: string, label: string, eik: ?string}>, groups: array<int, array{value: string, label: string}>}
     */
    public function scopes(): array
    {
        $entities = FinEntity::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)
            ->orderBy('sort')->orderBy('name')->get(['id', 'name', 'eik'])
            ->map(fn($e) => ['value' => 'entity:' . $e->id, 'label' => $e->name, 'eik' => $e->eik])->all();

        $groups = !$this->withGroups ? [] : FinConsolidationGroup::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn($g) => ['value' => 'group:' . $g->id, 'label' => $g->name])->all();

        return ['entities' => $entities, 'groups' => $groups];
    }

    /**
     * Scope array from the UI value ("entity:<id>" / "group:<id>"). An unknown value falls back to the first legal
     * entity, then the first group, then the whole company.
     */
    public function parseScope(?string $value): array
    {
        $m = [];

        if ($value && preg_match('/^(entity|group):(\d+)$/', $value, $m)) {
            $id = (int)$m[2];

            if ($m[1] === 'entity' && $this->entity($id)) {
                return ['entity_id' => $id];
            }

            if ($m[1] === 'group' && $this->withGroups && $this->group($id)) {
                return ['group_id' => $id];
            }
        }

        $scopes = $this->scopes();

        if (!empty($scopes['entities'])) {
            return $this->parseScope($scopes['entities'][0]['value']);
        }

        if (!empty($scopes['groups'])) {
            return $this->parseScope($scopes['groups'][0]['value']);
        }

        return [];
    }

    /** UI value of a scope: "entity:<id>", "group:<id>" or "" (whole company). */
    public function scopeValue(array $scope): string
    {
        if (!empty($scope['entity_id'])) {
            return 'entity:' . (int)$scope['entity_id'];
        }

        if (!empty($scope['group_id'])) {
            return 'group:' . (int)$scope['group_id'];
        }

        return '';
    }

    /**
     * Title data of the report: name, EIK, currency and the legal entities behind it.
     *
     * @return array{type: string, name: string, eik: ?string, currency: string, consolidated: bool, entities: string[], method: ?string}
     */
    public function scopeInfo(array $scope): array
    {
        $company = Company::find($this->companyId);
        $currency = $company->currency->currency_code ?? 'EUR';

        if (!empty($scope['group_id']) && ($group = $this->group((int)$scope['group_id']))) {
            return [
                'type' => 'group', 'name' => $group->name, 'eik' => null, 'currency' => $group->base_currency ?: $currency,
                'consolidated' => true, 'entities' => array_values($this->groupEntityNames($group->id)), 'method' => $group->method,
            ];
        }

        if (!empty($scope['entity_id']) && ($entity = $this->entity((int)$scope['entity_id']))) {
            return [
                'type' => 'entity', 'name' => $entity->name, 'eik' => $entity->eik ?: null, 'currency' => $entity->currency ?: $currency,
                'consolidated' => false, 'entities' => [$entity->name], 'method' => null,
            ];
        }

        return [
            'type' => 'company', 'name' => (string)($company->company_name ?? ''), 'eik' => null, 'currency' => $currency,
            'consolidated' => false, 'entities' => [], 'method' => null,
        ];
    }

    /**
     * Years that can be selected: from the first to the last year with entries (newest first).
     *
     * @return int[]
     */
    public function years(): array
    {
        $min = FinEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->min('entry_date');
        $max = FinEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->max('entry_date');

        if (!$min || !$max) {
            return [(int)now()->year];
        }

        return range((int)Carbon::parse($max)->year, (int)Carbon::parse($min)->year);
    }

    // ---------------------------------------------------------------------------------------------
    // Balance sheet
    // ---------------------------------------------------------------------------------------------

    /**
     * Balance sheet at 31.12. of $year with the comparative column (31.12. of the previous year).
     */
    public function balanceSheet(int $year, array $scope): array
    {
        $key = 'bs|' . $year . '|' . $this->scopeValue($scope);

        return $this->cache[$key] ??= $this->buildBalanceSheet($year, $scope);
    }

    protected function buildBalanceSheet(int $year, array $scope): array
    {
        $income = $this->incomeStatement($year, $scope);
        $columns = ['current' => $year, 'previous' => $year - 1];
        $values = [];
        $unmapped = [];
        $modes = [];
        $taxAccrued = false;

        foreach ($columns as $column => $y) {
            $fig = $this->figures($y, $scope);
            [$leaves, $columnUnmapped] = $this->balanceLeaves($fig);

            foreach ($columnUnmapped as $code => $amount) {
                $unmapped[$code][$column] = $amount;
            }

            // Result of the year: income statement C.5; "added" mode uses only the booked part (profit before tax
            // less the profit tax taken from tax accounts)
            $pat = $income['values'][$column]['C.5'];
            $booked = $income['tax_mode'] === 'actual' ? $pat : $income['values'][$column]['C.3'];
            $estimatedTax = round($booked - $pat, 2);
            $pool = $leaves['liab.A.III.1'] ?? 0.0;

            $plain = $this->fold(NssMapping::BALANCE_ROWS, $leaves);
            $difference = $plain['asset.total'] - $plain['liab.total'];

            if (abs($difference) > self::TOLERANCE && abs($difference - $booked) <= self::TOLERANCE) {
                $modes[$column] = 'added';
                $leaves['liab.A.III.1'] = $pool;
            }
            else {
                $modes[$column] = 'included';
                $leaves['liab.A.III.1'] = $pool - $booked;
            }

            $leaves['liab.A.III.2'] = $pat;

            if (abs($estimatedTax) >= 0.005) {
                $leaves['liab.C.II.4'] = ($leaves['liab.C.II.4'] ?? 0.0) + $estimatedTax;
                $taxAccrued = true;
            }

            if ($scope && !empty($scope['group_id']) && ($fig['nci'] ?? 0) > 0.004) {
                $leaves['liab.A.nci'] = $fig['nci'];
            }

            $values[$column] = $this->fold(NssMapping::BALANCE_ROWS, $leaves);
        }

        $accounts = $this->accounts();
        $unmappedRows = [];

        foreach ($unmapped as $code => $byColumn) {
            $unmappedRows[] = ['code' => (string)$code, 'name' => $accounts[$code]['name'] ?? '', 'current' => $byColumn['current'] ?? 0.0, 'previous' => $byColumn['previous'] ?? 0.0];
        }

        $totals = [
            'assets' => ['current' => $values['current']['asset.total'], 'previous' => $values['previous']['asset.total']],
            'liabilities' => ['current' => $values['current']['liab.total'], 'previous' => $values['previous']['liab.total']],
        ];
        $totals['difference'] = [
            'current' => round($totals['assets']['current'] - $totals['liabilities']['current'], 2),
            'previous' => round($totals['assets']['previous'] - $totals['liabilities']['previous'], 2),
        ];

        $info = $this->scopeInfo($scope);
        $notes = [__('databackd-gaps-statutory.notes.contra')];
        $notes[] = __('databackd-gaps-statutory.notes.result_' . ($modes['current'] === 'added' ? 'added' : 'included'));
        $notes[] = __('databackd-gaps-statutory.notes.result_year', ['tax' => $income['tax_mode'] === 'estimated' ? __('databackd-gaps-statutory.notes.result_year_estimated') : '']);

        if ($taxAccrued) {
            $notes[] = __('databackd-gaps-statutory.notes.tax_accrued');
        }

        if ($info['consolidated']) {
            $notes[] = __('databackd-gaps-statutory.notes.consolidated');
        }

        return [
            'kind' => 'balance', 'year' => $year, 'previous_year' => $year - 1, 'currency' => $info['currency'],
            'dates' => ['current' => $year . '-12-31', 'previous' => ($year - 1) . '-12-31'],
            'rows' => $this->rows(NssMapping::BALANCE_ROWS, $values, $this->rowAccounts('balance')),
            'unmapped' => $unmappedRows,
            'notes' => array_values(array_filter($notes)),
            'totals' => $totals,
            'balanced' => abs($totals['difference']['current']) < 0.01 && abs($totals['difference']['previous']) < 0.01,
            'result_mode' => $modes,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Income statement
    // ---------------------------------------------------------------------------------------------

    /**
     * Income statement (by nature of expense) for $year with the comparative column (the previous year).
     */
    public function incomeStatement(int $year, array $scope): array
    {
        $key = 'is|' . $year . '|' . $this->scopeValue($scope);

        return $this->cache[$key] ??= $this->buildIncomeStatement($year, $scope);
    }

    protected function buildIncomeStatement(int $year, array $scope): array
    {
        $columns = ['current' => $year, 'previous' => $year - 1];
        $leaves = [];
        $unmapped = [];
        $taxFound = false;

        foreach ($columns as $column => $y) {
            [$leaves[$column], $columnUnmapped] = $this->incomeLeaves($this->figures($y, $scope));

            foreach ($columnUnmapped as $code => $amount) {
                $unmapped[$code][$column] = $amount;
            }

            $taxFound = $taxFound || abs($leaves[$column][NssMapping::TAX_ROW] ?? 0.0) > 0.004;
        }

        $values = [];

        foreach ($columns as $column => $y) {
            if (!$taxFound) {
                $before = $this->fold(NssMapping::INCOME_ROWS, $leaves[$column]);
                $leaves[$column][NssMapping::TAX_ROW] = round(self::TAX_RATE * max($before['C.3'], 0.0), 2);
            }

            $values[$column] = $this->fold(NssMapping::INCOME_ROWS, $leaves[$column]);
        }

        $accounts = $this->accounts();
        $unmappedRows = [];

        foreach ($unmapped as $code => $byColumn) {
            $unmappedRows[] = ['code' => (string)$code, 'name' => $accounts[$code]['name'] ?? '', 'current' => $byColumn['current'] ?? 0.0, 'previous' => $byColumn['previous'] ?? 0.0];
        }

        $taxMode = $taxFound ? 'actual' : 'estimated';
        $info = $this->scopeInfo($scope);
        $rows = $this->rows(NssMapping::INCOME_ROWS, $values, $this->rowAccounts('income'), $taxMode === 'estimated'
            ? [NssMapping::TAX_ROW => __('databackd-gaps-statutory.rows.C_4_estimated', ['rate' => (int)round(self::TAX_RATE * 100)])]
            : []);

        $notes = [
            __('databackd-gaps-statutory.notes.expenses_positive'),
            $taxMode === 'actual'
                ? __('databackd-gaps-statutory.notes.tax_actual')
                : __('databackd-gaps-statutory.notes.tax_estimated', ['rate' => (int)round(self::TAX_RATE * 100)]),
            __('databackd-gaps-statutory.notes.no_sold_assets'),
            __('databackd-gaps-statutory.notes.licences'),
        ];

        if ($info['consolidated']) {
            $notes[] = __('databackd-gaps-statutory.notes.consolidated');
        }

        return [
            'kind' => 'income', 'year' => $year, 'previous_year' => $year - 1, 'currency' => $info['currency'],
            'dates' => ['current' => $year . '-12-31', 'previous' => ($year - 1) . '-12-31'],
            'rows' => $rows,
            'unmapped' => $unmappedRows,
            'notes' => $notes,
            'tax_mode' => $taxMode,
            'values' => $values,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Warnings and data coverage
    // ---------------------------------------------------------------------------------------------

    /**
     * Messages for the user about the selected year: missing / partial data, accounts without a row, a balance sheet
     * that does not balance.
     *
     * @return array<int, array{level: string, text: string}> level: danger|warning
     */
    public function warnings(int $year, array $scope): array
    {
        $out = [];

        foreach ([$year, $year - 1] as $y) {
            $coverage = $this->coverage($y, $scope);

            if ($coverage['flow_from'] === null && $coverage['balance_date'] === null) {
                $out[] = ['level' => 'warning', 'text' => __('databackd-gaps-statutory.warnings.no_data', ['year' => $y])];
                continue;
            }

            if ($coverage['flow_from'] === null) {
                $out[] = ['level' => 'warning', 'text' => __('databackd-gaps-statutory.warnings.no_flows', ['year' => $y])];
            }
            elseif ($coverage['flow_from']->gt(Carbon::create($y, 1, 1)) || $coverage['flow_to']->lt(Carbon::create($y, 12, 31))) {
                $out[] = ['level' => 'warning', 'text' => __('databackd-gaps-statutory.warnings.partial_year', [
                    'year' => $y, 'from' => $coverage['flow_from']->format('d.m.Y'), 'to' => $coverage['flow_to']->format('d.m.Y'),
                ])];
            }

            if ($coverage['balance_date'] === null) {
                $out[] = ['level' => 'warning', 'text' => __('databackd-gaps-statutory.warnings.no_balance', ['year' => $y])];
            }
            elseif ($coverage['balance_date']->lt(Carbon::create($y, 12, 1))) {
                $out[] = ['level' => 'warning', 'text' => __('databackd-gaps-statutory.warnings.balance_date', [
                    'year' => $y, 'date' => $coverage['balance_date']->format('d.m.Y'),
                ])];
            }
        }

        $balance =$this->balanceSheet($year, $scope);
        $income = $this->incomeStatement($year, $scope);

        $codes = [];

        foreach (array_merge($balance['unmapped'], $income['unmapped']) as $u) {
            $codes[$u['code']] = $u['code'] . ' ' . $u['name'];
        }

        if ($codes) {
            $out[] = ['level' => 'warning', 'text' => __('databackd-gaps-statutory.warnings.unmapped', ['accounts' => implode('; ', $codes)])];
        }

        foreach (['current' => $year, 'previous' => $year - 1] as $column => $y) {
            $difference = $balance['totals']['difference'][$column];

            if (abs($difference) >= 0.01 && $this->hasBalanceData($balance, $column)) {
                $out[] = ['level' => 'danger', 'text' => __('databackd-gaps-statutory.warnings.not_balanced', [
                    'year' => $y, 'difference' => number_format($difference, 2, ',', "\u{00A0}"),
                ])];
            }
        }

        return $out;
    }

    /**
     * Data coverage of one calendar year in the scope: first and last month with turnover (as dates: first day of the
     * first month, last day of the last month) and the latest balance date on or before 31.12.
     *
     * @return array{flow_from: ?Carbon, flow_to: ?Carbon, balance_date: ?Carbon}
     */
    public function coverage(int $year, array $scope): array
    {
        $key = 'cov|' . $year . '|' . $this->scopeValue($scope);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $empty = ['flow_from' => null, 'flow_to' => null, 'balance_date' => null];
        $ids = $this->entityIds($scope);

        if ($ids === []) {
            return $this->cache[$key] = $empty;
        }

        $filters = $ids === null ? [] : ['entity_ids' => $ids];
        $from = Carbon::create($year, 1, 1)->toDateString();
        $to = Carbon::create($year, 12, 31)->toDateString();

        $flow = $this->data->query($filters)->where('kind', 'flow')->whereBetween('entry_date', [$from, $to])
            ->selectRaw('MIN(entry_date) as first_date, MAX(entry_date) as last_date')->first();
        $balance = $this->data->query($filters)->where('kind', 'balance')->where('entry_date', '<=', $to)->max('entry_date');

        return $this->cache[$key] = [
            'flow_from' => $flow && $flow->first_date ? Carbon::parse($flow->first_date)->startOfMonth()->startOfDay() : null,
            'flow_to' => $flow && $flow->last_date ? Carbon::parse($flow->last_date)->endOfMonth()->startOfDay() : null,
            'balance_date' => $balance ? Carbon::parse($balance)->startOfDay() : null,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Data
    // ---------------------------------------------------------------------------------------------

    /**
     * Raw figures of one calendar year in the scope.
     *
     * @return array{balance: array<string, float>, flow: array<string, float>, nci: float}
     *         balance: account code => balance at 31.12. (contra accounts negative); flow: code => turnover of the year
     */
    protected function figures(int $year, array $scope): array
    {
        $key = 'fig|' . $year . '|' . $this->scopeValue($scope);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->startOfDay();
        $balance = [];
        $flow = [];
        $nci = 0.0;

        if (!empty($scope['group_id']) && ($group = $this->group((int)$scope['group_id']))) {
            $service = new ConsolidationService();
            $result = $service->run($group, $from, $to);

            foreach ($result['lines'] as $line) {
                if (in_array($line['type'], ['revenue', 'expense'], true)) {
                    $flow[$line['code']] = (float)$line['consolidated'];
                }
                else {
                    // review A: analytic accounts of a contra account (2411 under 241) are subtracted too
                    $sign = ($line['contra'] ?? FinAccount::isContraCode((string)$line['code'])) ? -1 : 1;
                    $balance[$line['code']] = $sign * (float)$line['consolidated'];
                }
            }

            $nci = $service->minorityTotals($result)['equity'];
        }
        else {
            $filters = !empty($scope['entity_id']) ? ['entity_ids' => [(int)$scope['entity_id']]] : [];

            foreach (['revenue', 'expense'] as $type) {
                foreach ($this->data->pnl($from, $to, $filters)[$type] as $row) {
                    $flow[$row['code']] = (float)$row['amount'];
                }
            }

            foreach ($this->accounts() as $code => $account) {
                if (in_array($account['type'], ['asset', 'liability', 'equity'], true)) {
                    // every account (also each analytic one) is read on its own and mapped to its row - without sub-accounts,
                    // otherwise 5031 would be counted in 503 and again on its own (S12)
                    $balance[(string)$code] = $this->data->balanceAt($to, (string)$code, $filters, false);
                }
            }
        }

        return $this->cache[$key] = ['balance' => $balance, 'flow' => $flow, 'nci' => $nci];
    }

    /**
     * Sums the balances of one column per balance sheet row.
     *
     * @return array{0: array<string, float>, 1: array<string, float>} [row key => amount, unmapped code => amount]
     */
    protected function balanceLeaves(array $figures): array
    {
        $leaves = [];
        $unmapped = [];

        foreach ($this->accounts() as $code => $account) {
            if (!in_array($account['type'], ['asset', 'liability', 'equity'], true)) {
                continue;
            }

            $amount = (float)($figures['balance'][(string)$code] ?? 0.0);
            [$row, $how] = NssMapping::balanceRow($account);
            $leaves[$row] = ($leaves[$row] ?? 0.0) + $amount;

            if ($how === NssMapping::FALLBACK && abs($amount) >= 0.005) {
                $unmapped[(string)$code] = round($amount, 2);
            }
        }

        return [$leaves, $unmapped];
    }

    /**
     * Sums the turnover of one column per income statement row.
     *
     * @return array{0: array<string, float>, 1: array<string, float>}
     */
    protected function incomeLeaves(array $figures): array
    {
        $leaves = [];
        $unmapped = [];
        $accounts = $this->accounts();

        foreach ($figures['flow'] as $code => $amount) {
            $account = $accounts[(string)$code] ?? ['code' => (string)$code, 'type' => 'expense', 'subtype' => null, 'name' => ''];
            [$row, $how] = NssMapping::incomeRow($account);
            $leaves[$row] = ($leaves[$row] ?? 0.0) + (float)$amount;

            if ($how === NssMapping::FALLBACK && abs($amount) >= 0.005) {
                $unmapped[(string)$code] = round((float)$amount, 2);
            }
        }

        return [$leaves, $unmapped];
    }

    /**
     * Evaluates all rows of a structure: rows that receive accounts take the leaf amounts, the others are sums /
     * differences of other rows.
     *
     * @return array<string, float>
     */
    protected function fold(array $structure, array $leaves): array
    {
        $values = [];
        $resolve = function (string $key) use (&$resolve, &$values, $structure, $leaves): float {
            if (array_key_exists($key, $values)) {
                return $values[$key];
            }

            $op = $structure[$key][3] ?? null;

            if ($op === null) {
                $value = (float)($leaves[$key] ?? 0.0);
            }
            elseif ($op[0] === 'sum') {
                $value = array_sum(array_map($resolve, $op[1]));
            }
            else {
                $value = $resolve($op[1]) - $resolve($op[2]);
            }

            return $values[$key] = round($value, 2) + 0.0;
        };

        foreach (array_keys($structure) as $key) {
            $resolve($key);
        }

        return $values;
    }

    /**
     * Rows for the screen / exports from the structure and the evaluated columns.
     *
     * @param array<string, string> $names row key => name that replaces the standard one
     */
    protected function rows(array $structure, array $values, array $rowAccounts, array $names = []): array
    {
        $rows = [];

        foreach ($structure as $key => [$code, $level, $type, , $optional]) {
            $name = $names[$key] ?? __('databackd-gaps-statutory.rows.' . NssMapping::langKey($key));

            if ($type === 'heading') {
                $rows[] = ['key' => $key, 'code' => NssMapping::displayCode($code), 'name' => $name, 'current' => null, 'previous' => null, 'level' => $level, 'type' => $type, 'accounts' => []];
                continue;
            }

            $current = $values['current'][$key] ?? 0.0;
            $previous = $values['previous'][$key] ?? 0.0;

            if ($optional && abs($current) < 0.005 && abs($previous) < 0.005) {
                continue;
            }

            $rows[] = [
                'key' => $key, 'code' => NssMapping::displayCode($code), 'name' => $name,
                'current' => $current, 'previous' => $previous, 'level' => $level, 'type' => $type,
                'accounts' => $rowAccounts[$key] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * Codes of the chart accounts mapped to every row (all accounts of the chart, with or without amounts).
     *
     * @return array<string, string[]>
     */
    protected function rowAccounts(string $statement): array
    {
        $map = [];

        foreach ($this->accounts() as $code => $account) {
            if ($statement === 'balance' && in_array($account['type'], ['asset', 'liability', 'equity'], true)) {
                $map[NssMapping::balanceRow($account)[0]][] = (string)$code;
            }
            elseif ($statement === 'income' && in_array($account['type'], ['revenue', 'expense'], true)) {
                $map[NssMapping::incomeRow($account)[0]][] = (string)$code;
            }
        }

        return $map;
    }

    /** Chart of accounts of the company: code => [id, code, name, type, subtype]. */
    protected function accounts(): array
    {
        if ($this->accountsCache === null) {
            $this->accountsCache = [];

            $all = DB::table('fin_accounts')->where('company_id', $this->companyId)->orderBy('code')->get(['id', 'code', 'name', 'type', 'subtype', 'parent_id'])->keyBy('id');

            foreach ($all as $a) {
                $this->accountsCache[(string)$a->code] = [
                    'id' => (int)$a->id, 'code' => (string)$a->code, 'name' => (string)$a->name, 'type' => $a->type, 'subtype' => $a->subtype,
                    'synthetic' => $this->syntheticOf($a, $all),
                ];
            }
        }

        return $this->accountsCache;
    }

    /**
     * Synthetic account (3 digits) of an account: from its own code, otherwise from the nearest parent that has one
     * (analytic accounts with free codes such as "A1" under 503); '' when there is none.
     */
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

    /**
     * Legal entities of the scope: null = all entries of the company, [] = a group without included entities.
     *
     * @return int[]|null
     */
    protected function entityIds(array $scope): ?array
    {
        if (!empty($scope['entity_id'])) {
            return [(int)$scope['entity_id']];
        }

        if (!empty($scope['group_id'])) {
            return array_map('intval', array_keys($this->groupEntityNames((int)$scope['group_id'])));
        }

        return null;
    }

    /** @return array<int, string> entity id => name of the included members of a group */
    protected function groupEntityNames(int $groupId): array
    {
        return DB::table('fin_consolidation_group_entities as m')
            ->join('fin_entities as e', 'e.id', '=', 'm.entity_id')
            ->where('m.group_id', $groupId)->where('m.company_id', $this->companyId)->where('m.include', 1)
            ->orderBy('e.sort')->orderBy('e.id')
            ->pluck('e.name', 'e.id')->all();
    }

    protected function entity(int $id): ?FinEntity
    {
        return FinEntity::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->find($id);
    }

    protected function group(int $id): ?FinConsolidationGroup
    {
        return FinConsolidationGroup::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->find($id);
    }

    /** Whether a balance sheet column has any amount (an empty year must not produce a "not balanced" error). */
    protected function hasBalanceData(array $balance, string $column): bool
    {
        return abs($balance['totals']['assets'][$column]) >= 0.005 || abs($balance['totals']['liabilities'][$column]) >= 0.005;
    }

}
