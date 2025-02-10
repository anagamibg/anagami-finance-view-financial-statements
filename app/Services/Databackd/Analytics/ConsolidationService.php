<?php

namespace App\Services\Databackd\Analytics;

use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinConsolidationGroup;
use App\Models\Databackd\FinEntry;
use App\Scopes\CompanyScope;
use App\Services\Databackd\Gaps\FxRates\FxRateService; // AFV (S8)
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consolidation of the legal entities of a group (F7).
 *
 * How the numbers are built (all in the group base currency):
 *  1. Per included entity (member.include = true) the P&L accounts are the SUM(amount_base) of `flow` entries in
 *     the period, the balance sheet accounts are the latest `balance` entry of (entity, account) on or before $to
 *     (same logic as FinancialDataService::balanceAt; the contra account 241 stays a positive number on its line
 *     and is subtracted when assets are summed). Then x fx_rate (null = 1) and, for the proportional method,
 *     x ownership % (member.ownership_pct, else fin_entities.ownership_pct, else 100). Result: `by_entity`.
 *     AFV (S8) foreign currency: `amount_base` is already in the COMPANY currency (the importer converted it),
 *     so nothing is converted twice. The only exception: an included entity whose fin_entities.currency differs from
 *     the group base currency AND whose member row has no manual fx_rate (a manual fx_rate wins) - for its entries
 *     that are recorded in the entity's own currency (fin_entries.currency = fin_entities.currency) the original
 *     `amount` is converted to the group currency with the dated rates of fin_fx_rates (FxRateService): flows at the
 *     rate of the entry date, balances at the rate of the end date ($to). Entries in any other currency, and entries
 *     without a usable rate, keep their `amount_base` (the old behaviour; fxMissing() lists the entities). Eliminations
 *     and derived intra-group balances use the same converted values, so both sides of a pair stay consistent.
 *     AFV (S10) group currency != company currency: `amount_base` (company currency) is converted to the group
 *     currency too (FxRateService; BGN <-> EUR is the fixed rate), at the same dates - for every included entity
 *     without a manual fx_rate, and in the fallback paths above (entry in another currency than the entity's, or no
 *     dated rate for the entity currency). No rate -> `amount_base` as before and fxMissing() lists the entity
 *     (currency = the company currency). When the group currency equals the company currency nothing changes.
 *  2. Eliminations (only if the group has eliminate_intercompany) - a negative number per account; each fin_entries row
 *     is eliminated at most once (its id goes to `eliminated_entries[account_id]`). An entry is intra-group when its
 *     counterparty_name equals the name of ANOTHER included entity. Rules, in `sort` order:
 *       - eliminate_counterparty {accounts?}: intra-group flow entries on the P&L accounts (default: all revenue and
 *         expense accounts) and intra-group `balance` entries on 411/401.
 *       - eliminate_account_pair {pairs}: for each pair [A, B] the intra-group entries on A and on B.
 *         If both are balance sheet accounts (e.g. 411/401) and no `balance` entry carries an intra-group
 *         counterparty (S12: not even one an earlier rule already eliminated), the open intra-group balance is DERIVED: the intra-group sales
 *         (revenue entries whose counterparty is a group entity) of the calendar month of the balance date are taken
 *         as unsettled at period end and eliminated symmetrically from the seller's receivable (asset side) and the
 *         buyer's payable (liability side), never more than min(seller's A, buyer's B). The listed entry ids are
 *         then the supporting intra-group sales, they are not necessarily equal to the eliminated amount.
 *     With the proportional method an entry is eliminated at the ownership share of its own entity (derived balances:
 *     at the lower share of the two sides, to keep the balance sheet balanced).
 *  3. Adjustments (always applied, after the eliminations, in `sort` order): reclassify moves the current net amount
 *     of `from` to `to` (only between accounts of the same type, a missing `to` line is added); exclude_account
 *     zeroes the account (adjustment = -current net amount).
 *  4. Non-controlling interest (full method, minority_interest = true, entity share < 100%): `minority` of a line is
 *     the sum over entities of by_entity x (100 - share)/100. It is informational and is NOT subtracted from
 *     `consolidated`. On balance sheet lines it is the NCI part of that line; on the equity lines it is the NCI in
 *     equity (see minorityTotals()); on P&L lines it is the NCI part of the line (NCI in profit = revenue - expense).
 *  5. consolidated = sum(by_entity) + eliminations + adjustments.
 *  6. balance_check: assets (contra subtracted) vs liabilities + equity of the consolidated lines. Equity (121)
 *     already contains the accumulated profit in the demo model, so the profit for the period is not added again.
 *
 * Result format (all amounts in the group base currency, positive in the sign convention of the account type):
 * [
 *   'entities' => [entity_id => name, ...],                 // included entities
 *   'lines' => [[                                           // one line per account (P&L and balance sheet)
 *       'account_id' => int, 'code' => string, 'name' => string, 'type' => asset|liability|equity|revenue|expense,
 *       'contra' => bool,                                   // contra account or its analytic (241, 2411) - subtracted
 *       'by_entity' => [entity_id => float, ...],           // after FX rate and ownership (proportional method)
 *       'eliminations' => float,                            // negative = reduces the amount
 *       'adjustments' => float,                             // reclassifications and exclusions
 *       'minority' => float,                                // non-controlling interest share (full method)
 *       'consolidated' => float,
 *   ], ...],
 *   'eliminated_entries' => [account_id => [fin_entries.id, ...], ...],
 *   'balance_check' => ['assets' => float, 'liabilities_equity' => float, 'difference' => float],
 *   'pnl' => [...lines of type revenue|expense...],
 *   'balance' => [...lines of type asset|liability|equity...],
 * ]
 */
class ConsolidationService
{

    /** Balance sheet accounts that the default eliminate_counterparty rule also looks at. */
    const DEFAULT_BALANCE_CODES = ['411', '401'];

    /** AFV (S8): entities of the last run() that had entries without a usable rate (S10: also company -> group currency). */
    protected array $fxMissing = [];

    /**
     * AFV (S8): the entities of the last run() whose own-currency entries could not be converted because
     * fin_fx_rates has no usable rate (their amount_base was used instead).
     *
     * @return array<int, array{entity: string, currency: string, group_currency: string, date: string}> by entity id; `date` = first date without a rate
     */
    public function fxMissing(): array
    {
        return $this->fxMissing;
    }

    /** AFV (S12): names shared by several included entities of the last run() (case-insensitive). */
    protected array $duplicateNames = [];

    /**
     * AFV (S12): names (as typed) that more than one included entity of the last run() carries. An entry whose
     * counterparty is such a name cannot be matched to one entity, so it is not treated as intra-group.
     *
     * @return string[]
     */
    public function duplicateNames(): array
    {
        return array_values($this->duplicateNames);
    }

    public function run(FinConsolidationGroup $g, Carbon $from, Carbon $to): array
    {
        $this->fxMissing = []; // AFV (S8)
        $this->duplicateNames = []; // AFV (S12)
        $ctx = $this->context($g);

        if (empty($ctx['entities'])) {
            return self::emptyResult();
        }

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $this->loadAmounts($ctx, $from, $to);

        $rules = $g->rules()->get();

        if ($g->eliminate_intercompany) {
            foreach ($rules as $rule) {
                $this->applyElimination($ctx, $rule->type, (array)($rule->params ?? []), $from, $to);
            }
        }

        foreach ($rules as $rule) {
            $this->applyAdjustment($ctx, $rule->type, (array)($rule->params ?? []));
        }

        return $this->buildResult($ctx);
    }

    /**
     * The eliminated fin_entries of one account in the group (for the "eliminations" modal), ordered by date.
     *
     * @return Collection<int, FinEntry> with the `entity` and `account` relations loaded
     */
    public function eliminatedEntries(FinConsolidationGroup $g, int $accountId, Carbon $from, Carbon $to): Collection
    {
        $ids = $this->run($g, $from, $to)['eliminated_entries'][$accountId] ?? [];

        if (empty($ids)) {
            return new Collection();
        }

        return FinEntry::withoutGlobalScope(CompanyScope::class)
            ->with(['entity', 'account'])
            ->whereIn('id', $ids)
            ->orderBy('entry_date')->orderBy('id')
            ->get();
    }

    /**
     * Resolver for FormulaEngine / RatioCalculator: callable (string $variable) => ?float over the consolidated
     * result of the group. Variables: account codes ("703"), and the totals "revenue", "expense", "profit",
     * "assets", "liabilities", "equity" (contra accounts subtracted from assets).
     */
    public function resolver(FinConsolidationGroup $g, Carbon $from, Carbon $to): callable
    {
        $result = $this->run($g, $from, $to);
        $values = [];
        $totals = ['revenue' => 0.0, 'expense' => 0.0, 'assets' => 0.0, 'liabilities' => 0.0, 'equity' => 0.0];

        foreach ($result['lines'] as $line) {
            $values[$line['code']] = $line['consolidated'];
            $sign = !empty($line['contra']) ? -1 : 1;
            $key = ['revenue' => 'revenue', 'expense' => 'expense', 'asset' => 'assets', 'liability' => 'liabilities', 'equity' => 'equity'][$line['type']];
            $totals[$key] += $sign * $line['consolidated'];
        }

        $values += array_map(fn($v) => round($v, 2), $totals);
        $values['profit'] = round($totals['revenue'] - $totals['expense'], 2);

        return fn(string $variable) => $values[$variable] ?? null;
    }

    /**
     * Resolver for FormulaEngine::evaluate() / RatioCalculator::calculate() that replaces the database values of the
     * formula variables with the consolidated ones (after eliminations and adjustments). REF[] is left to the engine,
     * which evaluates the other ratio with the same resolver.
     *
     * Balance sheet accounts are taken at the end of the period (AVG: with the opening balance of the day before the
     * period), P&L accounts as turnover for the period. Entity filters do not apply to consolidated data.
     */
    public function ratioResolver(FinConsolidationGroup $g, FormulaEngine $engine): callable
    {
        $runs = [];
        $run = function (Carbon $from, Carbon $to) use ($g, &$runs): array {
            $key = $from->toDateString() . '|' . $to->toDateString();

            if (!isset($runs[$key])) {
                $runs[$key] = [];

                foreach ($this->run($g, $from, $to)['lines'] as $line) {
                    $runs[$key][$line['account_id']] = $line['consolidated'];
                }
            }

            return $runs[$key];
        };

        $contra = FinAccount::contraMapFor((int)$g->company_id); // review A: 2411 under 241 is contra too

        return function (string $kind, string $arg, bool $avg, Carbon $from, Carbon $to, array $filters) use ($engine, $run, $contra): ?float {
            if ($kind === 'REF') {
                return null;
            }

            $period = $run($from, $to);
            $opening = null;
            $sum = 0.0;

            foreach ($engine->resolveAccounts($kind, $arg) as $a) {
                $sign = ($contra[(int)$a['id']] ?? FinAccount::isContraCode((string)$a['code'])) ? -1 : 1;
                $value = $period[$a['id']] ?? 0.0;

                if ($kind !== 'FLOW' && $avg && !in_array($a['type'], ['revenue', 'expense'], true)) {
                    $opening ??= $run($from->copy()->subDay(), $from->copy()->subDay());
                    $value = (($opening[$a['id']] ?? 0.0) + $value) / 2;
                }

                $sum += $sign * $value;
            }

            return round($sum, 2);
        };
    }

    /**
     * Non-controlling interest totals of a run() result: net assets (= NCI in equity) and NCI in profit.
     *
     * @return array{equity: float, profit: float}
     */
    public function minorityTotals(array $result): array
    {
        $equity = 0.0;
        $revenue = 0.0;
        $expense = 0.0;

        foreach ($result['lines'] as $line) {
            if ($line['type'] === 'equity') {
                $equity += $line['minority'];
            } elseif ($line['type'] === 'revenue') {
                $revenue += $line['minority'];
            } elseif ($line['type'] === 'expense') {
                $expense += $line['minority'];
            }
        }

        return ['equity' => round($equity, 2), 'profit' => round($revenue - $expense, 2)];
    }

    public static function emptyResult(): array
    {
        return [
            'entities' => [],
            'lines' => [],
            'eliminated_entries' => [],
            'balance_check' => ['assets' => 0.0, 'liabilities_equity' => 0.0, 'difference' => 0.0],
            'pnl' => [],
            'balance' => [],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Context and base amounts
    // ---------------------------------------------------------------------------------------------

    /** Included entities, factors, accounts. */
    protected function context(FinConsolidationGroup $g): array
    {
        $members = DB::table('fin_consolidation_group_entities as m')
            ->join('fin_entities as e', 'e.id', '=', 'm.entity_id')
            ->where('m.group_id', $g->id)
            ->where('m.include', 1)
            ->orderBy('e.sort')->orderBy('e.id')
            ->get(['m.entity_id', 'm.ownership_pct as member_pct', 'm.fx_rate', 'e.name', 'e.ownership_pct as entity_pct', 'e.currency as entity_currency']);

        $full = $g->method !== 'proportional';
        $ctx = [
            'company_id' => (int)$g->company_id,
            'entities' => [], 'names' => [], 'factor' => [], 'minority_share' => [],
            'accounts' => [], 'by_code' => [], 'base' => [], 'raw' => [], 'dates' => [],
            'elim' => [], 'elim_ids' => [], 'used' => [], 'derived' => [], 'adj' => [],
            // AFV (S8): entities converted with fin_fx_rates (entity id => its currency), the group currency, the rate service
            'fx_entities' => [], 'group_currency' => strtoupper(trim((string)($g->base_currency ?: 'EUR'))), 'fx' => null,
            // AFV (S10): currency of amount_base (the company currency, same fallback as FinEntryImporter), whether
            // amount_base must be converted to the group currency, and the entities that are loaded row by row
            // (fx_entities + entities without a manual fx_rate whose amount_base is converted)
            'company_currency' => $this->companyCurrency((int)$g->company_id), 'convert_base' => false, 'row_entities' => [],
        ];
        $ctx['convert_base'] = $ctx['company_currency'] !== $ctx['group_currency']; // AFV (S10)

        foreach ($members as $m) {
            $id = (int)$m->entity_id;
            $pct = $m->member_pct ?? $m->entity_pct ?? 100;
            $pct = (float)$pct;
            $manualFx = $m->fx_rate !== null && (float)$m->fx_rate > 0;
            $fx = $manualFx ? (float)$m->fx_rate : 1.0;

            $ctx['entities'][$id] = $m->name;
            $norm = $this->norm($m->name);

            // AFV (S12): an ambiguous name matches no entity (see duplicateNames())
            if (isset($ctx['names'][$norm]) || isset($this->duplicateNames[$norm])) {
                unset($ctx['names'][$norm]);
                $this->duplicateNames[$norm] = (string)$m->name;
            } else {
                $ctx['names'][$norm] = $id;
            }
            $ctx['factor'][$id] = $fx * ($full ? 1.0 : $pct / 100);
            $ctx['minority_share'][$id] = ($full && $g->minority_interest && $pct < 100) ? max(0.0, (100 - $pct) / 100) : 0.0;

            // AFV (S8): another currency than the group and no manual member fx_rate -> dated rates from fin_fx_rates
            $entityCurrency = strtoupper(trim((string)($m->entity_currency ?? '')));

            if (!$manualFx && $entityCurrency !== '' && $entityCurrency !== $ctx['group_currency']) {
                $ctx['fx_entities'][$id] = $entityCurrency;
            }

            // AFV (S10): a manual fx_rate means the user already converted - amount_base x fx_rate, nothing else
            if (!$manualFx && ($ctx['convert_base'] || isset($ctx['fx_entities'][$id]))) {
                $ctx['row_entities'][$id] = true;
            }
        }

        if (!empty($ctx['row_entities'])) {
            $ctx['fx'] = new FxRateService($ctx['company_id']); // AFV (S8, S10)
        }

        $accounts = DB::table('fin_accounts')->where('company_id', $ctx['company_id'])->get(['id', 'code', 'name', 'type', 'parent_id']);
        // review A: analytic accounts of a contra account (2411 / "A1" under 241) are contra too
        $contra = FinAccount::contraMap($accounts);

        foreach ($accounts as $a) {
            $ctx['accounts'][(int)$a->id] = ['code' => (string)$a->code, 'name' => $a->name, 'type' => $a->type, 'contra' => $contra[(int)$a->id] ?? false];
            $ctx['by_code'][(string)$a->code] = (int)$a->id;
        }

        return $ctx;
    }

    /** SUM per (entity, account): flows for the period, latest balances at $to. */
    protected function loadAmounts(array &$ctx, Carbon $from, Carbon $to): void
    {
        // AFV (S8): entities that are converted with fin_fx_rates are loaded by loadForeignAmounts() (S10: row_entities)
        $entityIds = array_values(array_diff(array_keys($ctx['entities']), array_keys($ctx['row_entities'])));

        $flows = DB::table('fin_entries as fe')
            ->join('fin_accounts as fa', 'fa.id', '=', 'fe.account_id')
            ->where('fe.company_id', $ctx['company_id'])
            ->where('fe.kind', 'flow')
            ->whereIn('fa.type', ['revenue', 'expense'])
            ->whereIn('fe.entity_id', $entityIds)
            ->whereBetween('fe.entry_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('fe.entity_id', 'fe.account_id')
            ->selectRaw('fe.entity_id, fe.account_id, SUM(fe.amount_base) as total')
            ->get();

        foreach ($flows as $r) {
            $this->setAmount($ctx, (int)$r->account_id, (int)$r->entity_id, (float)$r->total, null);
        }

        $balances = $this->latestBalances($ctx, $to, null, $entityIds)
            ->groupBy('fe.entity_id', 'fe.account_id')
            ->selectRaw('fe.entity_id, fe.account_id, SUM(fe.amount_base) as total, MAX(fe.entry_date) as last_date')
            ->get();

        foreach ($balances as $r) {
            $this->setAmount($ctx, (int)$r->account_id, (int)$r->entity_id, (float)$r->total, $r->last_date);
        }

        $this->loadForeignAmounts($ctx, $from, $to); // AFV (S8)
    }

    /**
     * AFV (S8): the same sums for the entities in another currency than the group (ctx fx_entities): rows in the
     * entity's own currency are converted from `amount` with the rate of the entry date (flows) or of $to (balances),
     * all other rows keep `amount_base`.
     * AFV (S10): all ctx row_entities; `amount_base` is converted company -> group currency when they differ.
     */
    protected function loadForeignAmounts(array &$ctx, Carbon $from, Carbon $to): void
    {
        $fxIds = array_keys($ctx['row_entities']); // AFV (S10)

        if (empty($fxIds)) {
            return;
        }

        $sums = [];

        $flows = DB::table('fin_entries as fe')
            ->join('fin_accounts as fa', 'fa.id', '=', 'fe.account_id')
            ->where('fe.company_id', $ctx['company_id'])
            ->where('fe.kind', 'flow')
            ->whereIn('fa.type', ['revenue', 'expense'])
            ->whereIn('fe.entity_id', $fxIds)
            ->whereBetween('fe.entry_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('fe.entity_id', 'fe.account_id', 'fe.currency', 'fe.entry_date')
            ->selectRaw('fe.entity_id, fe.account_id, fe.currency, fe.entry_date, SUM(fe.amount) as amount, SUM(fe.amount_base) as amount_base')
            ->get();

        foreach ($flows as $r) {
            $sums[(int)$r->account_id][(int)$r->entity_id]['total'] = ($sums[(int)$r->account_id][(int)$r->entity_id]['total'] ?? 0.0)
                + $this->groupAmount($ctx, (int)$r->entity_id, (float)$r->amount, (float)$r->amount_base, (string)$r->currency, (string)$r->entry_date);
        }

        foreach ($sums as $accountId => $perEntity) {
            foreach ($perEntity as $entityId => $s) {
                $this->setAmount($ctx, $accountId, $entityId, (float)$s['total'], null);
            }
        }

        $sums = [];

        $balances = $this->latestBalances($ctx, $to, null, $fxIds)
            ->groupBy('fe.entity_id', 'fe.account_id', 'fe.currency')
            ->selectRaw('fe.entity_id, fe.account_id, fe.currency, SUM(fe.amount) as amount, SUM(fe.amount_base) as amount_base, MAX(fe.entry_date) as last_date')
            ->get();

        foreach ($balances as $r) {
            $a = (int)$r->account_id;
            $e = (int)$r->entity_id;
            $sums[$a][$e]['total'] = ($sums[$a][$e]['total'] ?? 0.0)
                + $this->groupAmount($ctx, $e, (float)$r->amount, (float)$r->amount_base, (string)$r->currency, $to->toDateString());
            $sums[$a][$e]['date'] = max($sums[$a][$e]['date'] ?? '', (string)$r->last_date);
        }

        foreach ($sums as $accountId => $perEntity) {
            foreach ($perEntity as $entityId => $s) {
                $this->setAmount($ctx, $accountId, $entityId, (float)$s['total'], $s['date']);
            }
        }
    }

    /**
     * AFV (S8): the value of one entry (or a sum of entries of one date) in the group currency. Entries of an
     * entity that is not converted, or in another currency than the entity's own, keep `amount_base`; so do entries
     * without a usable rate (the entity is then listed in fxMissing()).
     * AFV (S10): in those fallback cases `amount_base` is converted from the company currency to the group
     * currency (baseToGroup()) when the two differ.
     */
    protected function groupAmount(array $ctx, int $entityId, float $amount, float $amountBase, ?string $currency, string $date): float
    {
        $entityCurrency = $ctx['fx_entities'][$entityId] ?? null;

        if ($entityCurrency === null || strtoupper(trim((string)$currency)) !== $entityCurrency) {
            return $this->baseToGroup($ctx, $entityId, $amountBase, $date); // AFV (S10)
        }

        $converted = $ctx['fx']->convert($amount, $entityCurrency, $ctx['group_currency'], $date);

        if ($converted === null) {
            $this->fxMissing[$entityId] ??= [
                'entity' => (string)($ctx['entities'][$entityId] ?? ''),
                'currency' => $entityCurrency,
                'group_currency' => $ctx['group_currency'],
                'date' => $date,
            ];

            return $this->baseToGroup($ctx, $entityId, $amountBase, $date); // AFV (S10)
        }

        return $converted;
    }

    /**
     * AFV (S10): `amount_base` (company currency) in the group currency on $date. Unchanged when the currencies
     * are equal or the entity has a manual fx_rate (not in row_entities); without a usable rate the amount is kept and
     * the entity is listed in fxMissing() (currency = the company currency).
     */
    protected function baseToGroup(array $ctx, int $entityId, float $amountBase, string $date): float
    {
        if (!$ctx['convert_base'] || !isset($ctx['row_entities'][$entityId])) {
            return $amountBase;
        }

        $converted = $ctx['fx']->convert($amountBase, $ctx['company_currency'], $ctx['group_currency'], $date);

        if ($converted === null) {
            $this->fxMissing[$entityId] ??= [
                'entity' => (string)($ctx['entities'][$entityId] ?? ''),
                'currency' => $ctx['company_currency'],
                'group_currency' => $ctx['group_currency'],
                'date' => $date,
            ];

            return $amountBase;
        }

        return $converted;
    }

    /** AFV (S10): the currency of fin_entries.amount_base - company->currency->currency_code ('EUR' like FinEntryImporter when none). */
    protected function companyCurrency(int $companyId): string
    {
        $code = DB::table('companies as c')
            ->join('currencies as cu', 'cu.id', '=', 'c.currency_id')
            ->where('c.id', $companyId)
            ->value('cu.currency_code');

        return strtoupper(trim((string)($code ?: 'EUR')));
    }

    /** AFV (S8): groupAmount() of a fin_entries row selected with amount, currency, entry_date (and kind). */
    protected function entryValue(array $ctx, object $row, ?Carbon $closing = null): float
    {
        $entityId = (int)$row->entity_id;

        if (!isset($ctx['row_entities'][$entityId])) { // AFV (S10): was fx_entities
            return (float)$row->amount_base;
        }

        $date = (($row->kind ?? 'flow') === 'balance' && $closing) ? $closing->toDateString() : (string)$row->entry_date;

        return $this->groupAmount($ctx, $entityId, (float)$row->amount, (float)$row->amount_base, (string)$row->currency, $date);
    }

    /**
     * Query on the latest `balance` entries per (entity, account) on or before $to (alias fe, joined fa).
     * AFV (S8): $entityIds restricts the entities (default: all included ones).
     */
    protected function latestBalances(array $ctx, Carbon $to, ?array $accountIds = null, ?array $entityIds = null)
    {
        $entityIds ??= array_keys($ctx['entities']);

        $latest = DB::table('fin_entries')
            ->where('company_id', $ctx['company_id'])
            ->where('kind', 'balance')
            ->whereIn('entity_id', $entityIds)
            ->where('entry_date', '<=', $to->toDateString())
            ->groupBy('entity_id', 'account_id')
            ->selectRaw('entity_id, account_id, MAX(entry_date) as last_date');

        if ($accountIds !== null) {
            $latest->whereIn('account_id', $accountIds);
        }

        return DB::table('fin_entries as fe')
            ->joinSub($latest, 'lb', function ($join) {
                $join->on('lb.entity_id', '=', 'fe.entity_id')
                    ->on('lb.account_id', '=', 'fe.account_id')
                    ->on('lb.last_date', '=', 'fe.entry_date');
            })
            ->join('fin_accounts as fa', 'fa.id', '=', 'fe.account_id')
            ->where('fe.company_id', $ctx['company_id'])
            ->where('fe.kind', 'balance')
            ->whereIn('fa.type', ['asset', 'liability', 'equity']);
    }

    protected function setAmount(array &$ctx, int $accountId, int $entityId, float $raw, ?string $date): void
    {
        if (!isset($ctx['accounts'][$accountId]) || !isset($ctx['factor'][$entityId])) {
            return;
        }

        $ctx['raw'][$accountId][$entityId] = $raw;
        $ctx['base'][$accountId][$entityId] = $raw * $ctx['factor'][$entityId];

        if ($date !== null) {
            $ctx['dates'][$accountId][$entityId] = $date;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Eliminations
    // ---------------------------------------------------------------------------------------------

    protected function applyElimination(array &$ctx, string $type, array $params, Carbon $from, Carbon $to): void
    {
        if ($type === 'eliminate_counterparty') {
            $codes = !empty($params['accounts']) ? (array)$params['accounts'] : null;
            $ids = [];

            if ($codes === null) {
                foreach ($ctx['accounts'] as $id => $a) {
                    if (in_array($a['type'], ['revenue', 'expense'], true) || in_array($a['code'], self::DEFAULT_BALANCE_CODES, true)) {
                        $ids[] = $id;
                    }
                }
            } else {
                $ids = $this->idsByCodes($ctx, $codes);
            }

            $this->eliminateTagged($ctx, $ids, $from, $to);

            return;
        }

        if ($type === 'eliminate_account_pair') {
            foreach ((array)($params['pairs'] ?? []) as $pair) {
                if (!is_array($pair) || count($pair) < 2) {
                    continue;
                }

                $ids = $this->idsByCodes($ctx, [$pair[0], $pair[1]]);

                if (count($ids) < 2) {
                    continue;
                }

                [$a, $b] = $ids;
                $eliminated = $this->eliminateTagged($ctx, [$a, $b], $from, $to);
                $typeA = $ctx['accounts'][$a]['type'];
                $typeB = $ctx['accounts'][$b]['type'];

                if ($eliminated === 0 && in_array($typeA, ['asset', 'liability'], true) && in_array($typeB, ['asset', 'liability'], true) && $typeA !== $typeB) {
                    $this->deriveBalancePair($ctx, $typeA === 'asset' ? $a : $b, $typeA === 'asset' ? $b : $a);
                }
            }
        }
    }

    /**
     * Eliminate (once per entry) the entries of the accounts whose counterparty is another included entity:
     * flows in the period for P&L accounts, the latest `balance` entries for balance sheet accounts.
     *
     * @return int number of intra-group entries found on the accounts - eliminated now or already by an earlier rule
     *             (S12: counting the earlier ones too keeps eliminate_account_pair from deriving the 411/401 balance a
     *             second time when eliminate_counterparty already eliminated the tagged intra-group balances)
     */
    protected function eliminateTagged(array &$ctx, array $accountIds, Carbon $from, Carbon $to): int
    {
        if (empty($accountIds) || count($ctx['entities']) < 2) {
            return 0;
        }

        $pnl = [];
        $bs = [];

        foreach ($accountIds as $id) {
            if (in_array($ctx['accounts'][$id]['type'], ['revenue', 'expense'], true)) {
                $pnl[] = $id;
            } else {
                $bs[] = $id;
            }
        }

        $names = array_keys($ctx['entities']);
        $nameList = array_values($ctx['entities']);
        $count = 0;
        $rows = collect();

        if (!empty($pnl)) {
            $rows = $rows->concat(DB::table('fin_entries as fe')
                ->where('fe.company_id', $ctx['company_id'])
                ->where('fe.kind', 'flow')
                ->whereIn('fe.account_id', $pnl)
                ->whereIn('fe.entity_id', $names)
                ->whereIn('fe.counterparty_name', $nameList)
                ->whereBetween('fe.entry_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('fe.id')
                ->get(['fe.id', 'fe.entity_id', 'fe.account_id', 'fe.amount_base', 'fe.counterparty_name', 'fe.amount', 'fe.currency', 'fe.entry_date', 'fe.kind'])); // AFV (S8): + amount, currency, entry_date, kind
        }

        if (!empty($bs)) {
            $rows = $rows->concat($this->latestBalances($ctx, $to, $bs)
                ->whereIn('fe.counterparty_name', $nameList)
                ->orderBy('fe.id')
                ->get(['fe.id', 'fe.entity_id', 'fe.account_id', 'fe.amount_base', 'fe.counterparty_name', 'fe.amount', 'fe.currency', 'fe.entry_date', 'fe.kind'])); // AFV (S8)
        }

        foreach ($rows as $r) {
            $id = (int)$r->id;

            if (!$this->isIntercompany($ctx, (int)$r->entity_id, $r->counterparty_name)) {
                continue;
            }

            $count++; // AFV (S12): found, also when an earlier rule eliminated it

            if (isset($ctx['used'][$id])) {
                continue;
            }

            $ctx['used'][$id] = true;
            $account = (int)$r->account_id;
            $ctx['elim'][$account] = ($ctx['elim'][$account] ?? 0.0) - $this->entryValue($ctx, $r, $to) * $ctx['factor'][(int)$r->entity_id]; // AFV (S8): converted value
            $ctx['elim_ids'][$account][] = $id;
        }

        return $count;
    }

    /**
     * Intra-group balances without a tagged counterparty: eliminate the intra-group sales of the month of the
     * balance date from the sellers' receivable ($assetId) and the buyers' payable ($liabilityId) symmetrically.
     */
    protected function deriveBalancePair(array &$ctx, int $assetId, int $liabilityId): void
    {
        $key = $assetId . ':' . $liabilityId;

        if (isset($ctx['derived'][$key])) {
            return;
        }

        $ctx['derived'][$key] = true;
        $dates = array_merge(array_values($ctx['dates'][$assetId] ?? []), array_values($ctx['dates'][$liabilityId] ?? []));

        if (empty($dates)) {
            return;
        }

        $balanceDate = Carbon::parse(max($dates));
        $revenueIds = array_keys(array_filter($ctx['accounts'], fn($a) => $a['type'] === 'revenue'));

        if (empty($revenueIds)) {
            return;
        }

        $sales = DB::table('fin_entries as fe')
            ->where('fe.company_id', $ctx['company_id'])
            ->where('fe.kind', 'flow')
            ->whereIn('fe.account_id', $revenueIds)
            ->whereIn('fe.entity_id', array_keys($ctx['entities']))
            ->whereIn('fe.counterparty_name', array_values($ctx['entities']))
            ->whereBetween('fe.entry_date', [$balanceDate->copy()->startOfMonth()->toDateString(), $balanceDate->copy()->endOfMonth()->toDateString()])
            ->orderBy('fe.id')
            ->get(['fe.id', 'fe.entity_id', 'fe.amount_base', 'fe.counterparty_name', 'fe.amount', 'fe.currency', 'fe.entry_date']); // AFV (S8): + amount, currency, entry_date

        $pairs = [];

        foreach ($sales as $r) {
            if (!$this->isIntercompany($ctx, (int)$r->entity_id, $r->counterparty_name)) {
                continue;
            }

            $buyer = $ctx['names'][$this->norm($r->counterparty_name)];
            $k = $r->entity_id . '>' . $buyer;
            $pairs[$k]['seller'] = (int)$r->entity_id;
            $pairs[$k]['buyer'] = $buyer;
            $pairs[$k]['amount'] = ($pairs[$k]['amount'] ?? 0.0) + $this->entryValue($ctx, $r); // AFV (S8): converted value
            $pairs[$k]['ids'][] = (int)$r->id;
        }

        ksort($pairs);
        $capSeller = $ctx['raw'][$assetId] ?? [];
        $capBuyer = $ctx['raw'][$liabilityId] ?? [];

        foreach ($pairs as $p) {
            $x = min($p['amount'], $capSeller[$p['seller']] ?? 0.0, $capBuyer[$p['buyer']] ?? 0.0);

            if ($x < 0.005) {
                continue;
            }

            $capSeller[$p['seller']] -= $x;
            $capBuyer[$p['buyer']] -= $x;
            $scaled = $x * min($ctx['factor'][$p['seller']], $ctx['factor'][$p['buyer']]);

            foreach ([$assetId, $liabilityId] as $account) {
                $ctx['elim'][$account] = ($ctx['elim'][$account] ?? 0.0) - $scaled;
                $ctx['elim_ids'][$account] = array_merge($ctx['elim_ids'][$account] ?? [], $p['ids']);
            }
        }
    }

    protected function isIntercompany(array $ctx, int $entityId, ?string $counterparty): bool
    {
        if ($counterparty === null || $counterparty === '') {
            return false;
        }

        $other = $ctx['names'][$this->norm($counterparty)] ?? null;

        return $other !== null && $other !== $entityId;
    }

    // ---------------------------------------------------------------------------------------------
    // Adjustments
    // ---------------------------------------------------------------------------------------------

    protected function applyAdjustment(array &$ctx, string $type, array $params): void
    {
        if ($type === 'reclassify') {
            $ids = $this->idsByCodes($ctx, [$params['from'] ?? '', $params['to'] ?? ''], true);

            if (count($ids) < 2 || $ids[0] === $ids[1] || $ctx['accounts'][$ids[0]]['type'] !== $ctx['accounts'][$ids[1]]['type']) {
                return;
            }

            [$from, $to] = $ids;
            $amount = $this->net($ctx, $from);
            $ctx['adj'][$from] = ($ctx['adj'][$from] ?? 0.0) - $amount;
            $ctx['adj'][$to] = ($ctx['adj'][$to] ?? 0.0) + $amount;

            return;
        }

        if ($type === 'exclude_account') {
            foreach ($this->idsByCodes($ctx, (array)($params['accounts'] ?? [])) as $id) {
                $ctx['adj'][$id] = ($ctx['adj'][$id] ?? 0.0) - $this->net($ctx, $id);
            }
        }
    }

    /** Current consolidated amount of an account (entities + eliminations + adjustments so far). */
    protected function net(array $ctx, int $accountId): float
    {
        return array_sum($ctx['base'][$accountId] ?? []) + ($ctx['elim'][$accountId] ?? 0.0) + ($ctx['adj'][$accountId] ?? 0.0);
    }

    // ---------------------------------------------------------------------------------------------
    // Result
    // ---------------------------------------------------------------------------------------------

    protected function buildResult(array $ctx): array
    {
        $typeOrder = ['revenue' => 0, 'expense' => 1, 'asset' => 2, 'liability' => 3, 'equity' => 4];
        $lines = [];

        foreach ($ctx['accounts'] as $id => $a) {
            $byEntity = [];
            $nonZero = false;
            $minority = 0.0;

            foreach ($ctx['entities'] as $entityId => $name) {
                $value = $ctx['base'][$id][$entityId] ?? 0.0;
                $byEntity[$entityId] = round($value, 2);
                $minority += $value * $ctx['minority_share'][$entityId];
                $nonZero = $nonZero || abs($byEntity[$entityId]) >= 0.005;
            }

            $elim = round($ctx['elim'][$id] ?? 0.0, 2);
            $adj = round($ctx['adj'][$id] ?? 0.0, 2);

            if (!$nonZero && abs($elim) < 0.005 && abs($adj) < 0.005) {
                continue;
            }

            $lines[] = [
                'account_id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'], 'contra' => $a['contra'],
                'by_entity' => $byEntity,
                'eliminations' => $elim,
                'adjustments' => $adj,
                'minority' => round($minority, 2),
                'consolidated' => round(array_sum($byEntity) + $elim + $adj, 2),
            ];
        }

        usort($lines, fn($x, $y) => [$typeOrder[$x['type']], $x['code']] <=> [$typeOrder[$y['type']], $y['code']]);

        $pnl = array_values(array_filter($lines, fn($l) => in_array($l['type'], ['revenue', 'expense'], true)));
        usort($pnl, fn($x, $y) => strcmp($x['code'], $y['code']));
        $balance = array_values(array_filter($lines, fn($l) => !in_array($l['type'], ['revenue', 'expense'], true)));

        $assets = 0.0;
        $liabilitiesEquity = 0.0;

        foreach ($balance as $l) {
            if ($l['type'] === 'asset') {
                $assets += $l['contra'] ? -$l['consolidated'] : $l['consolidated'];
            } else {
                $liabilitiesEquity += $l['consolidated'];
            }
        }

        $eliminated = [];

        foreach ($ctx['elim_ids'] as $accountId => $ids) {
            $eliminated[$accountId] = array_values(array_unique($ids));
        }

        return [
            'entities' => $ctx['entities'],
            'lines' => array_merge($pnl, $balance),
            'eliminated_entries' => $eliminated,
            'balance_check' => [
                'assets' => round($assets, 2),
                'liabilities_equity' => round($liabilitiesEquity, 2),
                'difference' => round($assets - $liabilitiesEquity, 2),
            ],
            'pnl' => $pnl,
            'balance' => $balance,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * Account ids of codes (unknown codes skipped; with $strict an unknown code empties the result).
     *
     * @return int[]
     */
    protected function idsByCodes(array $ctx, array $codes, bool $strict = false): array
    {
        $ids = [];

        foreach ($codes as $code) {
            $id = $ctx['by_code'][(string)$code] ?? null;

            if ($id === null) {
                if ($strict) {
                    return [];
                }

                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    protected function norm(?string $name): string
    {
        return mb_strtolower(trim((string)$name));
    }

}
