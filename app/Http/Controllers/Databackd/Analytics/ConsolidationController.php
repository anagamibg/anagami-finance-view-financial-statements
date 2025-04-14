<?php

namespace App\Http\Controllers\Databackd\Analytics;

use App\Exports\Databackd\Analytics\ConsolidationExport;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinConsolidationGroup;
use App\Models\Databackd\FinConsolidationGroupEntity;
use App\Models\Databackd\FinEntry;
use App\Services\Databackd\Analytics\ConsolidationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Consolidation worksheet (F7): consolidated income statement and balance sheet of a group of legal entities,
 * drill-down to the eliminated entries and Excel export. All numbers come from ConsolidationService::run().
 */
class ConsolidationController extends AccountBaseController
{

    const PERIOD_TYPES = ['month', 'quarter', 'year', 'custom'];

    /** Maximum number of eliminated entries listed in the modal. */
    const MODAL_LIMIT = 500;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-analytics-consolidation.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    /**
     * Worksheet: ?group=<id>&period_type=month|quarter|year|custom&period=<2026-10|2026-Q3|2026>&from=&to=&tab=pnl|balance
     */
    public function index(Request $request)
    {
        $this->groups = FinConsolidationGroup::orderBy('name')->get();
        $this->group = $this->resolveGroup($request, $this->groups);
        $this->periodInfo = $this->resolvePeriod($request);
        $this->activeTab = $request->query('tab') === 'balance' ? 'balance' : 'pnl';
        $this->periodOptions = $this->periodOptions($this->periodInfo);

        $service = app(ConsolidationService::class);
        $result = $this->group
            ? $service->run($this->group, $this->periodInfo['from'], $this->periodInfo['to'])
            : ConsolidationService::emptyResult();

        // AFV (S12): warnings of the run (missing FX rates, ambiguous entity names) and the empty state reason
        $this->fxMissing = $this->group ? $service->fxMissing() : [];
        $this->duplicateNames = $this->group ? $service->duplicateNames() : [];
        $this->includedCount = $this->group
            ? FinConsolidationGroupEntity::where('group_id', $this->group->id)->where('include', true)->count() : 0;
        $this->emptyReason = !$this->group ? 'no_groups' : ($this->includedCount === 0 ? 'no_entities' : 'no_data');
        $this->groupUrl = $this->group ? route('databackd.analytics.consolidation-rules.show', $this->group->id) : null;
        $this->createGroupUrl = route('databackd.analytics.consolidation-rules.index', ['create' => 1]);
        // audit 2026-10-30 (G11): FX rates belong to databackd_data
        $this->fxRatesUrl = Route::has('databackd.gaps.fx-rates.index') && databackd_module('databackd_data') ? route('databackd.gaps.fx-rates.index') : null;
        $this->withoutEntity = $this->group
            ? FinEntry::whereNull('entity_id')->whereBetween('entry_date', [$this->periodInfo['from']->toDateString(), $this->periodInfo['to']->toDateString()])->count()
            : 0;

        $subtypes = $this->accountSubtypes();
        $this->result = $result;
        $this->hasData = !empty($result['lines']) && !empty($result['entities']);
        $this->currency = $this->group->base_currency ?? 'EUR';
        $this->entities = $result['entities'];
        $this->pnlRows = ConsolidationExport::statementRows($result, 'pnl', $subtypes);
        $this->balanceRows = ConsolidationExport::statementRows($result, 'balance', $subtypes);

        // Cards and chart data
        $totals = ConsolidationExport::typeTotals($result, $subtypes);
        $this->revenue = $totals['revenue']['consolidated'];
        $this->expense = $totals['expense']['consolidated'];
        $this->profit = $this->revenue - $this->expense;
        $this->eliminated = max(abs($totals['revenue']['eliminations']), abs($totals['expense']['eliminations']));
        // AFV (S12): with the full method both sides of an intra-group sale are eliminated, so the eliminated revenue
        // and expense should be equal; a difference means one side is not tagged with the counterparty (profit is affected)
        $this->elimRevenue = abs((float)$totals['revenue']['eliminations']);
        $this->elimExpense = abs((float)$totals['expense']['eliminations']);
        $this->elimMismatch = $this->group && $this->group->method === 'full' && abs($this->elimRevenue - $this->elimExpense) >= 0.01;

        $this->chart = $this->contributionChart($result['entities'], $totals);
        $this->eliminatedAccounts = collect($result['eliminated_entries'] ?? [])->map(fn($ids) => count($ids))->all();

        $check = $result['balance_check'] ?? [];
        $this->balanceCheck = [
            'assets' => (float)($check['assets'] ?? 0),
            'liabilities_equity' => (float)($check['liabilities_equity'] ?? 0),
            'difference' => (float)($check['difference'] ?? 0),
        ];
        $this->balanceOk = abs($this->balanceCheck['difference']) < 0.005;

        $this->rulesUrl = Route::has('databackd.analytics.consolidation-rules.index') ? route('databackd.analytics.consolidation-rules.index') : null;
        $this->exportUrl = $this->group ? route('databackd.analytics.consolidation.export', $this->queryParams()) : null;
        $this->eliminationsUrl = $this->group
            ? route('databackd.analytics.consolidation.eliminations', $this->queryParams()) . '&account_id='
            : null;

        return view('databackd.analytics.consolidation.index', $this->data);
    }

    /**
     * AJAX modal: the eliminated fin_entries of one account (all eliminated entries of the group when no account
     * is given). ?group=&from=&to=&account_id=
     */
    public function eliminations(Request $request)
    {
        $group = $this->resolveGroup($request, FinConsolidationGroup::orderBy('name')->get());
        abort_if(!$group, 404);

        $period = $this->resolvePeriod($request);
        $accountId = (int)$request->query('account_id');
        $account = $accountId ? FinAccount::findOrFail($accountId) : null;

        // AFV (S12): one run - the entry ids and the eliminated amount of the worksheet (group currency, after
        // FX rate and ownership share) come from the same result
        $result = app(ConsolidationService::class)->run($group, $period['from'], $period['to']);
        $eliminated = $result['eliminated_entries'] ?? [];
        $ids = $account
            ? ($eliminated[$accountId] ?? [])
            : array_values(array_unique(array_merge(...array_values($eliminated ?: [[]]))));
        $all = FinEntry::with(['entity', 'account'])->whereIn('id', $ids ?: [0])->orderBy('entry_date')->orderBy('id')->get();
        $lines = collect($result['lines'] ?? []);

        $this->totalCount = $all->count();
        $this->total = (float)$all->sum('amount_base');
        $this->entries = $all->take(self::MODAL_LIMIT);
        $this->limit = self::MODAL_LIMIT;
        $this->account = $account;
        $this->currency = $group->base_currency;
        $this->periodLabel = $period['label'];
        // AFV (S12): amount_base is in the company currency; the worksheet amount is in the group currency
        $this->entryCurrency = strtoupper((string)(company()->currency->currency_code ?? 'EUR'));
        $this->worksheetAmount = $account
            ? (float)($lines->firstWhere('account_id', $accountId)['eliminations'] ?? 0)
            : null;
        // Derived 411/401 eliminations list the supporting intra-group sales (flows) on a balance sheet account
        $this->derived = $account && !in_array($account->type, ['revenue', 'expense'], true)
            && $all->contains(fn($e) => $e->kind === 'flow');

        return view('databackd.analytics.consolidation.eliminations', $this->data);
    }

    public function export(Request $request)
    {
        $group = $this->resolveGroup($request, FinConsolidationGroup::orderBy('name')->get());
        abort_if(!$group, 404);

        $period = $this->resolvePeriod($request);
        $result = app(ConsolidationService::class)->run($group, $period['from'], $period['to']);

        $export = new ConsolidationExport($result, $this->accountSubtypes(), [
            'group' => $group->name,
            'period' => $period['label'],
            'method' => __('databackd-analytics-common.methods.' . $group->method),
            'currency' => $group->base_currency,
        ]);

        return Excel::download($export, 'consolidation-' . $period['from']->format('Ymd') . '-' . $period['to']->format('Ymd') . '.xlsx');
    }

    protected function resolveGroup(Request $request, $groups): ?FinConsolidationGroup
    {
        $id = (int)$request->query('group');

        return $groups->firstWhere('id', $id) ?? $groups->first();
    }

    /**
     * @return array{type: string, value: string, from: Carbon, to: Carbon, label: string}
     */
    protected function resolvePeriod(Request $request): array
    {
        $type = $request->query('period_type');
        $value = (string)$request->query('period');

        if (!in_array($type, self::PERIOD_TYPES, true)) {
            $type = ($request->filled('from') && $request->filled('to')) ? 'custom' : 'month';
        }

        $m = [];

        if ($type === 'month' && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m)) {
            $from = Carbon::create((int)$m[1], (int)$m[2], 1)->startOfDay();
            $to = $from->copy()->endOfMonth();
        }
        elseif ($type === 'quarter' && preg_match('/^(\d{4})-Q([1-4])$/', $value, $m)) {
            $from = Carbon::create((int)$m[1], ((int)$m[2] - 1) * 3 + 1, 1)->startOfDay();
            $to = $from->copy()->addMonths(2)->endOfMonth();
        }
        elseif ($type === 'year' && preg_match('/^\d{4}$/', $value)) {
            $from = Carbon::create((int)$value, 1, 1)->startOfDay();
            $to = $from->copy()->endOfYear();
        }
        elseif ($type === 'custom' && $request->filled('from') && $request->filled('to')) {
            try {
                $from = Carbon::parse($request->query('from'))->startOfDay();
                $to = Carbon::parse($request->query('to'))->endOfDay();
            } catch (\Throwable $e) {
                $from = $to = null;
            }

            if ($from && $to && $from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }
        }

        if (empty($from) || empty($to)) {
            // Default: the current month
            $type = 'month';
            $from = now()->startOfMonth()->startOfDay();
            $to = $from->copy()->endOfMonth();
        }

        if ($type === 'month') {
            $value = $from->format('Y-m');
            $label = __('databackd-analytics-consolidation.months.' . $from->month) . ' ' . $from->year;
        }
        elseif ($type === 'quarter') {
            $q = (int)ceil($from->month / 3);
            $value = $from->year . '-Q' . $q;
            $label = __('databackd-analytics-consolidation.quarters.' . $q) . ' ' . $from->year;
        }
        elseif ($type === 'year') {
            $value = (string)$from->year;
            $label = (string)$from->year;
        }
        else {
            $value = '';
            $label = $from->format('d.m.Y') . ' – ' . $to->format('d.m.Y');
        }

        return ['type' => $type, 'value' => $value, 'from' => $from, 'to' => $to, 'label' => $label];
    }

    /**
     * Options of the period select for every period type (month, quarter, year), newest first.
     * Based on the range of the financial entries of the company.
     */
    protected function periodOptions(array $selected): array
    {
        $min = FinEntry::min('entry_date');
        $max = FinEntry::max('entry_date');
        $first = $min ? Carbon::parse($min)->startOfMonth() : now()->startOfMonth()->subMonths(17);
        $last = Carbon::parse($max ?: now())->startOfMonth();

        if ($last->lt(now()->startOfMonth())) {
            $last = now()->startOfMonth();
        }

        $months = $quarters = $years = [];

        for ($d = $last->copy(); $d->gte($first); $d->subMonth()) {
            $months[$d->format('Y-m')] = __('databackd-analytics-consolidation.months.' . $d->month) . ' ' . $d->year;
            $q = (int)ceil($d->month / 3);
            $quarters[$d->year . '-Q' . $q] = __('databackd-analytics-consolidation.quarters.' . $q) . ' ' . $d->year;
            $years[(string)$d->year] = (string)$d->year;
        }

        $options = ['month' => $months, 'quarter' => $quarters, 'year' => $years];

        // Keep an explicitly requested value that is outside the data range
        if ($selected['type'] !== 'custom' && !isset($options[$selected['type']][$selected['value']])) {
            $options[$selected['type']] = [$selected['value'] => $selected['label']] + $options[$selected['type']];
        }

        return $options;
    }

    /** Exact dates of the selected period, for the export and the elimination modal links. */
    protected function queryParams(): array
    {
        $p = $this->periodInfo;

        return ['group' => $this->group->id, 'period_type' => 'custom', 'from' => $p['from']->toDateString(), 'to' => $p['to']->toDateString()];
    }

    /** [account_id => subtype] used to group the statement lines. */
    protected function accountSubtypes(): array
    {
        return FinAccount::pluck('subtype', 'id')->all();
    }

    /**
     * Revenue and profit of every legal entity (before eliminations) for the stacked / grouped bar and the pie.
     */
    protected function contributionChart(array $entities, array $totals): array
    {
        $labels = $revenue = $profit = [];

        foreach ($entities as $id => $name) {
            $r = (float)($totals['revenue']['entities'][$id] ?? 0);
            $e = (float)($totals['expense']['entities'][$id] ?? 0);
            $labels[] = $name;
            $revenue[] = round($r, 2);
            $profit[] = round($r - $e, 2);
        }

        $palette = ['#1D82F5', '#2CB100', '#FFBB44', '#8E44AD', '#E74C3C', '#17A2B8'];

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'profit' => $profit,
            'colors' => array_slice(array_merge($palette, $palette), 0, max(count($labels), 1)),
            'has_revenue' => array_sum($revenue) > 0,
        ];
    }

}
