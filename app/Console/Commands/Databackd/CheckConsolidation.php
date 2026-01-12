<?php

namespace App\Console\Commands\Databackd;

use App\Models\Databackd\FinConsolidationGroup;
use App\Models\Databackd\FinConsolidationGroupEntity;
use App\Models\Databackd\FinConsolidationRule;
use App\Models\Databackd\FinEntity;
use App\Scopes\CompanyScope;
use App\Services\Databackd\Analytics\ConsolidationService;
use App\Services\Databackd\Data\FinEntryImporter; // AFV (S10)
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * databackd: assertions over the consolidation engine (demo group + scenario groups built inside a rolled back
 * transaction). Exit code is not zero when an assertion fails.
 */
class CheckConsolidation extends Command
{

    const DEMO_GROUP = 'Демо Финанс Груп — консолидирано';

    protected $signature = 'databackd:check-consolidation {--group= : Name of the group to check (default: the demo group)} {--from=2026-10-01} {--to=2026-10-31} {--show : Print the key numbers}';

    protected $description = 'Check the consolidation engine against the demo consolidation group';

    protected int $passed = 0;

    protected int $failed = 0;

    protected Carbon $from;

    protected Carbon $to;

    public function handle(): int
    {
        $name = $this->option('group') ?: self::DEMO_GROUP;
        $group = FinConsolidationGroup::withoutGlobalScope(CompanyScope::class)->where('name', $name)->first();

        if (!$group) {
            $this->error("Консолидационната група „{$name}“ не е намерена. Създайте я (php artisan databackd:demo --only=AnalyticsDemoSeeder) или подайте --group=<име>.");

            return self::FAILURE;
        }

        $this->from = Carbon::parse($this->option('from'))->startOfDay();
        $this->to = Carbon::parse($this->option('to'))->startOfDay();
        $service = new ConsolidationService();

        // ---- demo group as configured -----------------------------------------------------------
        $res = $service->run($group, $this->from, $this->to);
        $revenue = $this->sumType($res, 'revenue');

        $this->check('резултатът има всички ключове', empty(array_diff(['entities', 'lines', 'eliminated_entries', 'balance_check', 'pnl', 'balance'], array_keys($res)))
            && empty(array_diff(['account_id', 'code', 'name', 'type', 'by_entity', 'eliminations', 'adjustments', 'minority', 'consolidated'], array_keys($res['lines'][0] ?? [])))
            && isset($res['balance_check']['assets'], $res['balance_check']['liabilities_equity'], $res['balance_check']['difference']));
        $this->check('групата има включени юридически лица', count($res['entities']) >= 2, count($res['entities']) . ' юр. лица');
        $this->check('lines = pnl + balance', count($res['lines']) === count($res['pnl']) + count($res['balance']) && count($res['pnl']) > 0 && count($res['balance']) > 0);

        $elim = $this->elimTotal($res);
        $this->check('елиминациите са различни от 0', abs($elim) > 0.005, number_format($elim, 2));

        $entityRevenue = array_sum($revenue['by_entity']);
        $this->check('консолидираните приходи са по-малко от сбора на юр. лица', $revenue['consolidated'] < $entityRevenue,
            number_format($revenue['consolidated'], 2) . ' < ' . number_format($entityRevenue, 2));

        $ids = array_merge(...array_values($res['eliminated_entries'] ?: [[]]));
        $this->check('елиминираните записи не са празни', count($ids) > 0, count($ids) . ' записа');

        $names = array_map(fn($n) => mb_strtolower(trim($n)), array_values($res['entities']));
        $bad = 0;
        $rows = $ids ? DB::table('fin_entries as fe')->join('fin_entities as e', 'e.id', '=', 'fe.entity_id')
            ->whereIn('fe.id', $ids)->get(['fe.counterparty_name', 'e.name as own', 'fe.kind']) : collect();
        foreach ($rows as $r) {
            $cp = mb_strtolower(trim((string)$r->counterparty_name));
            $isSupport = $r->kind === 'flow'; // flows are also listed as support of derived 411/401 eliminations
            if (!in_array($cp, $names, true) || ($cp === mb_strtolower(trim($r->own)) && !$isSupport)) {
                $bad++;
            }
        }
        $this->check('всеки елиминиран запис е с контрагент юр. лице от групата', $rows->count() > 0 && $bad === 0, $bad . ' грешни');

        $assets = $res['balance_check']['assets'];
        $diff = abs($res['balance_check']['difference']);
        $this->check('разликата в баланса е в допустим праг (< 1% от активите)', $assets > 0 && $diff < 0.01 * $assets,
            'активи ' . number_format($assets, 2) . ', разлика ' . number_format($res['balance_check']['difference'], 2));

        $this->check('сумите са закръглени до 2 знака', $this->allRounded($res));

        // independent check of the 703 / 602 elimination against a plain SQL sum
        $sql = $this->icFlowSum($res['entities'], $names);
        $line703 = $this->findLine($res, '703');
        $line602 = $this->findLine($res, '602');
        if ($group->eliminate_intercompany && $group->method === 'full' && $line703 && $line602 && $this->rulesEliminateFlows($group)) {
            $this->check('елиминацията на 703 и 602 съвпада със сбора на вътрешногрупните записи (SQL)',
                abs(-$line703['eliminations'] - $sql['revenue']) < 0.02 && abs(-$line602['eliminations'] - $sql['expense']) < 0.02,
                'SQL: ' . number_format($sql['revenue'], 2) . ' / ' . number_format($sql['expense'], 2));
        }

        // ---- scenario groups (transaction, rolled back) -----------------------------------------
        $entityIds = array_keys($res['entities']);
        DB::beginTransaction();

        try {
            $this->scenarios($service, $group, $entityIds);
        } finally {
            DB::rollBack();
        }

        if ($this->option('show')) {
            $this->showNumbers($res, $revenue, $elim);
        }

        $this->line('');
        $this->line("Успешни: {$this->passed}, неуспешни: {$this->failed}");

        return $this->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ---------------------------------------------------------------------------------------------

    protected function scenarios(ConsolidationService $service, FinConsolidationGroup $demo, array $entityIds): void
    {
        $companyId = (int)$demo->company_id;
        $rules = [
            ['eliminate_counterparty', []],
            ['eliminate_account_pair', ['pairs' => [['411', '401'], ['703', '602']]]],
        ];

        // full method, all rules
        $full = $this->makeGroup($companyId, ['method' => 'full'], $entityIds, $rules);
        $r = $service->run($full, $this->from, $this->to);

        $l411 = $this->findLine($r, '411');
        $l401 = $this->findLine($r, '401');
        $this->check('411 и 401 се елиминират симетрично', $l411 && $l401 && abs($l411['eliminations']) > 0.005
            && abs($l411['eliminations'] - $l401['eliminations']) < 0.005,
            $l411 ? number_format($l411['eliminations'], 2) . ' / ' . number_format($l401['eliminations'] ?? 0, 2) : 'няма ред');

        // the same entry is never eliminated twice by two rules
        $only = $this->makeGroup($companyId, ['method' => 'full'], $entityIds, [['eliminate_account_pair', ['pairs' => [['703', '602']]]]]);
        $ro = $service->run($only, $this->from, $this->to);
        $this->check('един запис не се елиминира два пъти от две правила',
            abs($this->findLine($r, '703')['eliminations'] - $this->findLine($ro, '703')['eliminations']) < 0.005
            && abs($this->findLine($r, '602')['eliminations'] - $this->findLine($ro, '602')['eliminations']) < 0.005
            && count($r['eliminated_entries'][$this->findLine($r, '703')['account_id']]) === count(array_unique($r['eliminated_entries'][$this->findLine($r, '703')['account_id']])));

        $this->check('балансът се връзва след елиминациите (разлика ≈ 0)', abs($r['balance_check']['difference']) < 0.05,
            number_format($r['balance_check']['difference'], 2));

        // minority interest
        $nci = $service->minorityTotals($r);
        $l121 = $this->findLine($r, '121');
        $minorityEntity = FinEntity::withoutGlobalScope(CompanyScope::class)->whereIn('id', $entityIds)->where('ownership_pct', '<', 100)->first();
        $this->check('неконтролиращото участие е > 0 при участие < 100% (пълен метод)', $minorityEntity && $nci['equity'] > 0 && $l121 && $l121['minority'] > 0,
            'капитал ' . number_format($nci['equity'], 2) . ', печалба ' . number_format($nci['profit'], 2));
        if ($minorityEntity && $l121) {
            $expected = $l121['by_entity'][$minorityEntity->id] * (100 - $minorityEntity->ownership_pct) / 100;
            $this->check('минорити върху 121 = (100 − %) от дела на юр. лице', abs($l121['minority'] - $expected) < 0.02);
        }

        $noMin = $this->makeGroup($companyId, ['method' => 'full', 'minority_interest' => false], $entityIds, $rules);
        $this->check('minority_interest=false → неконтролиращо участие 0', $service->minorityTotals($service->run($noMin, $this->from, $this->to)) === ['equity' => 0.0, 'profit' => 0.0]);

        // proportional vs full
        $none = [];
        $fullNoElim = $this->makeGroup($companyId, ['method' => 'full', 'eliminate_intercompany' => false], $entityIds, $none);
        $prop = $this->makeGroup($companyId, ['method' => 'proportional', 'eliminate_intercompany' => false], $entityIds, $none);
        $rf = $service->run($fullNoElim, $this->from, $this->to);
        $rp = $service->run($prop, $this->from, $this->to);
        $cf = $this->sumType($rf, 'revenue');
        $cp = $this->sumType($rp, 'revenue');

        if ($minorityEntity) {
            $id = $minorityEntity->id;
            $this->check('пропорционалният метод дава по-малка сума за юр. лице с % < 100',
                $cp['by_entity'][$id] < $cf['by_entity'][$id] && $cp['consolidated'] < $cf['consolidated'],
                number_format($cp['by_entity'][$id], 2) . ' < ' . number_format($cf['by_entity'][$id], 2));
        } else {
            $this->check('има юр. лице с участие < 100% за проверка на пропорционалния метод', false);
        }

        // eliminate_intercompany = false
        $this->check('eliminate_intercompany=false → елиминации = 0', abs($this->elimTotal($rf)) < 0.005 && empty($rf['eliminated_entries']));

        // include = false
        if (count($entityIds) > 1) {
            $partial = $this->makeGroup($companyId, ['method' => 'full', 'eliminate_intercompany' => false], $entityIds, $none, [$entityIds[0] => ['include' => false]]);
            $rpart = $service->run($partial, $this->from, $this->to);
            $this->check('include=false изключва юридическото лице', count($rpart['entities']) === count($entityIds) - 1 && !isset($rpart['entities'][$entityIds[0]])
                && !isset($this->sumType($rpart, 'revenue')['by_entity'][$entityIds[0]])
                && $this->sumType($rpart, 'revenue')['consolidated'] < $cf['consolidated']);
        }

        // reclassification
        $rec = $this->makeGroup($companyId, ['method' => 'full'], $entityIds, [['reclassify', ['from' => '703', 'to' => '704']]]);
        $rr = $service->run($rec, $this->from, $this->to);
        $b703 = $this->findLine($rf, '703')['consolidated'];
        $b704 = $this->findLine($rf, '704')['consolidated'] ?? 0.0;
        $a703 = $this->findLine($rr, '703');
        $a704 = $this->findLine($rr, '704');
        $this->check('рекласификацията премества сума от 703 към 704', $a703 && $a704 && abs($a703['adjustments'] + $b703) < 0.01
            && abs($a704['adjustments'] - $b703) < 0.01 && abs($a703['consolidated']) < 0.01 && abs($a704['consolidated'] - ($b703 + $b704)) < 0.01);
        $this->check('рекласификацията запазва сбора на приходите', abs($this->sumType($rr, 'revenue')['consolidated'] - $cf['consolidated']) < 0.02);

        // reclassify to an account without a line (709 has data; use 721 interest income is likely present too - create a row case via 702)
        $rec2 = $this->makeGroup($companyId, ['method' => 'full'], $entityIds, [['reclassify', ['from' => '709', 'to' => '702']]]);
        $r2 = $service->run($rec2, $this->from, $this->to);
        $this->check('рекласификация към сметка без ред добавя ред', $this->findLine($r2, '702') !== null && abs($this->findLine($r2, '709')['consolidated']) < 0.01);

        // exclude account
        $exc = $this->makeGroup($companyId, ['method' => 'full'], $entityIds, [['exclude_account', ['accounts' => ['703']]]]);
        $re = $service->run($exc, $this->from, $this->to);
        $e703 = $this->findLine($re, '703');
        $this->check('exclude_account нулира сметката в консолидираното', $e703 && abs($e703['consolidated']) < 0.01 && $e703['adjustments'] < 0);

        // empty group
        $empty = $this->makeGroup($companyId, ['method' => 'full'], [], $rules);
        $this->check('празна група връща празна структура без грешка', $service->run($empty, $this->from, $this->to) === ConsolidationService::emptyResult());

        // fx rate
        $fx = $this->makeGroup($companyId, ['method' => 'full', 'eliminate_intercompany' => false], $entityIds, $none, [$entityIds[0] => ['fx_rate' => 2]]);
        $rfx = $service->run($fx, $this->from, $this->to);
        $this->check('валутният курс умножава сумите на юр. лице', abs($this->sumType($rfx, 'revenue')['by_entity'][$entityIds[0]] - 2 * $cf['by_entity'][$entityIds[0]]) < 0.02);

        $this->edgeScenarios($service, $companyId, $entityIds, $rules, $rf); // AFV (S12)
        $this->groupCurrencyScenarios($service, $companyId, $entityIds, $rules, $r, $rf);
        $this->contraAnalyticScenario($service, $companyId, $entityIds, $none);
    }

    /**
     * Review A: an analytic account of the contra 241 (2411) is subtracted like 241 - in balance_check, in the totals of
     * resolver() and in ratioResolver(). Runs inside the rolled back transaction of handle().
     */
    protected function contraAnalyticScenario(ConsolidationService $service, int $companyId, array $entityIds, array $none): void
    {
        $a241 = DB::table('fin_accounts')->where('company_id', $companyId)->where('code', '241')->first();

        if (!$a241 || !$entityIds) {
            $this->check('има сметка 241 и юр. лица за проверката на 2411', false);

            return;
        }

        $group = $this->makeGroup($companyId, ['method' => 'full', 'eliminate_intercompany' => false], $entityIds, $none);
        $before = $service->run($group, $this->from, $this->to);
        $resolverBefore = $service->resolver($group, $this->from, $this->to)('assets');
        $engine = new \App\Services\Databackd\Analytics\FormulaEngine($companyId);
        $acc24Before = ($service->ratioResolver($group, $engine))('ACC', '24*', false, $this->from, $this->to, []);

        $id2411 = DB::table('fin_accounts')->insertGetId(['company_id' => $companyId, 'code' => '2411', 'name' => 'check 2411', 'type' => 'asset',
            'subtype' => 'non_current_asset', 'parent_id' => $a241->id, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fin_entries')->insert(['company_id' => $companyId, 'entity_id' => $entityIds[0], 'account_id' => $id2411,
            'entry_date' => $this->to->toDateString(), 'kind' => 'balance', 'amount' => 500, 'currency' => 'EUR', 'amount_base' => 500,
            'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

        $after = $service->run($group, $this->from, $this->to);
        $line = $this->findLine($after, '2411');
        $this->check('2411 под 241 е контра сметка (ред с contra = true)', $line && $line['contra'] === true && ($this->findLine($after, '241')['contra'] ?? false) === true);
        $this->check('2411 се изважда от активите в balance_check (−500)', abs($after['balance_check']['assets'] - ($before['balance_check']['assets'] - 500)) < 0.01,
            number_format($before['balance_check']['assets'], 2) . ' → ' . number_format($after['balance_check']['assets'], 2));
        $this->check('2411 се изважда от активите в resolver() (−500)', abs($service->resolver($group, $this->from, $this->to)('assets') - ($resolverBefore - 500)) < 0.01);
        $engine = new \App\Services\Databackd\Analytics\FormulaEngine($companyId);
        $this->check('ratioResolver: ACC[24*] изважда и 2411 (−500)',
            abs(($service->ratioResolver($group, $engine))('ACC', '24*', false, $this->from, $this->to, []) - ($acc24Before - 500)) < 0.01);
    }

    /**
     * AFV (S12): edge cases - tagged intra-group balances with both the counterparty and the pair rule (no
     * double elimination), an entity that is its own counterparty, a group with only excluded members, an entity in
     * two groups, a deleted member entity, the proportional method with eliminations, duplicate entity names.
     * Runs inside the rolled back transaction of handle().
     */
    protected function edgeScenarios(ConsolidationService $service, int $companyId, array $entityIds, array $rules, array $plain): void
    {
        if (count($entityIds) < 2) {
            $this->line('  (пропуснато: гранични случаи — нужни са поне 2 юр. лица)');

            return;
        }

        [$a, $b] = $entityIds;
        $names = FinEntity::withoutGlobalScope(CompanyScope::class)->whereIn('id', [$a, $b])->pluck('name', 'id');
        $accounts = DB::table('fin_accounts')->where('company_id', $companyId)->pluck('id', 'code');
        $none = [];

        // 1) tagged intra-group 411/401 balances + eliminate_counterparty (all) + pair 411/401: eliminated once, no derived amount on top
        if (isset($accounts['411'], $accounts['401'])) {
            $tagged = 1000.0;

            foreach ([[$a, '411', $names[$b]], [$b, '401', $names[$a]]] as [$entity, $code, $counterparty]) {
                $last = DB::table('fin_entries')->where('company_id', $companyId)->where('entity_id', $entity)->where('account_id', $accounts[$code])
                    ->where('kind', 'balance')->where('entry_date', '<=', $this->to->toDateString())->max('entry_date') ?: $this->to->toDateString();
                DB::table('fin_entries')->insert([
                    'company_id' => $companyId, 'entity_id' => $entity, 'account_id' => $accounts[$code], 'entry_date' => $last,
                    'kind' => 'balance', 'amount' => $tagged, 'currency' => 'EUR', 'amount_base' => $tagged,
                    'counterparty_name' => $counterparty, 'description' => 'S12 check', 'source' => 'manual',
                ]);
            }

            $both = $this->makeGroup($companyId, ['method' => 'full'], [$a, $b], $rules);
            $rb = $service->run($both, $this->from, $this->to);
            $l411 = $this->findLine($rb, '411');
            $l401 = $this->findLine($rb, '401');
            // expected: only the tagged latest balances (plain SQL), no balance derived from the intra-group sales on top
            $sql = fn(string $code) => (float)DB::table('fin_entries as fe')
                ->join('fin_entities as e', 'e.id', '=', 'fe.entity_id')
                ->joinSub(DB::table('fin_entries')->where('company_id', $companyId)->where('kind', 'balance')->whereIn('entity_id', [$a, $b])
                    ->where('account_id', $accounts[$code])->where('entry_date', '<=', $this->to->toDateString())
                    ->groupBy('entity_id')->selectRaw('entity_id, MAX(entry_date) as d'), 'lb', fn($j) => $j->on('lb.entity_id', '=', 'fe.entity_id')->on('lb.d', '=', 'fe.entry_date'))
                ->where('fe.kind', 'balance')->where('fe.account_id', $accounts[$code])
                ->whereIn('fe.counterparty_name', [$names[$a], $names[$b]])->whereColumn('fe.counterparty_name', '!=', 'e.name')
                ->sum('fe.amount_base');
            $e411 = $sql('411');
            $e401 = $sql('401');
            $this->check('отбелязани вътрешногрупови салда 411/401 се елиминират веднъж (правило по контрагент + двойка сметки)',
                $l411 && $l401 && $e411 >= $tagged && abs($l411['eliminations'] + $e411) < 0.005 && abs($l401['eliminations'] + $e401) < 0.005,
                $l411 ? number_format($l411['eliminations'], 2) . ' / ' . number_format($l401['eliminations'] ?? 0, 2)
                    . ', SQL -' . number_format($e411, 2) . ' / -' . number_format($e401, 2) : 'няма ред');

            DB::table('fin_entries')->where('company_id', $companyId)->where('description', 'S12 check')->delete();
        }

        // 2) an entry whose counterparty is the entity itself is not intra-group
        $revenueId = $accounts['703'] ?? null;

        if ($revenueId) {
            DB::table('fin_entries')->insert([
                'company_id' => $companyId, 'entity_id' => $a, 'account_id' => $revenueId, 'entry_date' => $this->from->toDateString(),
                'kind' => 'flow', 'amount' => 777, 'currency' => 'EUR', 'amount_base' => 777,
                'counterparty_name' => $names[$a], 'description' => 'S12 check', 'source' => 'manual',
            ]);
            $self = $this->makeGroup($companyId, ['method' => 'full'], [$a, $b], [['eliminate_counterparty', ['accounts' => ['703']]]]);
            $rs = $service->run($self, $this->from, $this->to);
            $selfId = (int)DB::table('fin_entries')->where('company_id', $companyId)->where('description', 'S12 check')->value('id');
            $this->check('запис с контрагент самото юр. лице не се елиминира', !in_array($selfId, $rs['eliminated_entries'][$revenueId] ?? [], true));
            DB::table('fin_entries')->where('company_id', $companyId)->where('description', 'S12 check')->delete();
        }

        // 3) all members excluded -> empty result
        $off = $this->makeGroup($companyId, ['method' => 'full'], $entityIds, $rules, array_fill_keys($entityIds, ['include' => false]));
        $this->check('група само с изключени юр. лица връща празна структура', $service->run($off, $this->from, $this->to) === ConsolidationService::emptyResult());

        // 4) the same entity in two groups: its own numbers do not depend on the group
        $one = $this->makeGroup($companyId, ['method' => 'full', 'eliminate_intercompany' => false], [$a], $none);
        $r1 = $service->run($one, $this->from, $this->to);
        $bad = 0;

        foreach ($plain['lines'] as $l) {
            $other = collect($r1['lines'])->firstWhere('account_id', $l['account_id']);
            $bad += abs(($other['by_entity'][$a] ?? 0.0) - ($l['by_entity'][$a] ?? 0.0)) < 0.005 ? 0 : 1;
        }

        $this->check('юр. лице в две групи има едни и същи суми и в двете', $bad === 0 && count($r1['entities']) === 1, $bad . ' разминавания');

        // 5) a deleted member entity (no entries) leaves the group without errors
        $tmpId = DB::table('fin_entities')->insertGetId(['company_id' => $companyId, 'name' => 'S12 check entity', 'country' => 'BG', 'currency' => 'EUR', 'ownership_pct' => 100, 'is_active' => 1, 'sort' => 999]);
        $withTmp = $this->makeGroup($companyId, ['method' => 'full'], [$a, $b, $tmpId], $rules);
        $before = count($service->run($withTmp, $this->from, $this->to)['entities']);
        DB::table('fin_entities')->where('id', $tmpId)->delete();
        $after = $service->run($withTmp->fresh(), $this->from, $this->to);
        $this->check('изтрито юр. лице отпада от групата без грешка', $before === 3 && count($after['entities']) === 2 && !isset($after['entities'][$tmpId]));

        // 6) proportional method: an intra-group entry is eliminated at the share of its own entity
        $partial = FinEntity::withoutGlobalScope(CompanyScope::class)->whereIn('id', $entityIds)->where('ownership_pct', '<', 100)->value('id');
        $prop = $this->makeGroup($companyId, ['method' => 'proportional'], $entityIds, [['eliminate_counterparty', ['accounts' => ['703', '602']]]]);
        $rp = $service->run($prop, $this->from, $this->to);
        $l703 = $this->findLine($rp, '703');
        $expected = 0.0;
        $ids = $l703 ? ($rp['eliminated_entries'][$l703['account_id']] ?? []) : [];

        foreach (DB::table('fin_entries as fe')->join('fin_entities as e', 'e.id', '=', 'fe.entity_id')->whereIn('fe.id', $ids ?: [0])->get(['fe.amount_base', 'e.ownership_pct']) as $row) {
            $expected += (float)$row->amount_base * (float)$row->ownership_pct / 100;
        }

        $this->check('пропорционален метод: вътрешногруповият запис се елиминира по дела на своето юр. лице',
            $partial && $l703 && count($ids) > 0 && abs($l703['eliminations'] + $expected) < 0.02,
            $l703 ? number_format($l703['eliminations'], 2) . ' / -' . number_format($expected, 2) : 'няма ред');

        // 7) two entities with the same name (case-insensitive): reported, no intra-group elimination between them
        $origName = $names[$b];
        DB::table('fin_entities')->where('id', $b)->update(['name' => mb_strtoupper($names[$a])]);
        $dup = $this->makeGroup($companyId, ['method' => 'full'], [$a, $b], $rules);
        $rd = $service->run($dup, $this->from, $this->to);
        $this->check('юр. лица с еднакви имена се отчитат в duplicateNames()', count($service->duplicateNames()) === 1 && !empty($rd['entities']));
        DB::table('fin_entities')->where('id', $b)->update(['name' => $origName]);
    }

    /**
     * AFV (S10): group currency != company currency - amount_base (company currency) is converted to the group
     * currency. The expected values are the company-currency runs ($full: all rules, $plain: no eliminations) x the
     * fixed BGN rate. Only for a company in EUR (the demo); entity currencies are changed inside the transaction.
     */
    protected function groupCurrencyScenarios(ConsolidationService $service, int $companyId, array $entityIds, array $rules, array $full, array $plain): void
    {
        $companyCurrency = strtoupper((string)DB::table('companies as c')->join('currencies as cu', 'cu.id', '=', 'c.currency_id')->where('c.id', $companyId)->value('cu.currency_code'));

        if ($companyCurrency !== 'EUR' || count($entityIds) < 2) {
            $this->line('  (пропуснато: групова валута ≠ фирмена валута — нужна е фирма в EUR с поне 2 юр. лица)');

            return;
        }

        $k = FinEntryImporter::BGN_PER_EUR;
        $first = $entityIds[0];
        $none = [];

        // 1) group in BGN: the first entity in USD (its EUR entries take the fallback path), the others in BGN (not converted with fin_fx_rates)
        DB::table('fin_entities')->where('id', $first)->update(['currency' => 'USD']);
        DB::table('fin_entities')->whereIn('id', array_slice($entityIds, 1))->update(['currency' => 'BGN']);
        $bgn = $this->makeGroup($companyId, ['method' => 'full', 'base_currency' => 'BGN'], $entityIds, $rules);
        $rb = $service->run($bgn, $this->from, $this->to);
        $bad = $this->scaledMismatches($full, $rb, array_fill_keys($entityIds, $k), true);
        $this->check('група в BGN при фирмена валута EUR: юр. лица, елиминации и консолидирано = EUR × 1.95583',
            $bad === 0 && count($rb['lines']) === count($full['lines']) && empty($service->fxMissing())
            && abs($rb['balance_check']['difference']) < 0.05 * $k,
            $bad . ' разминавания, разлика в баланса ' . number_format($rb['balance_check']['difference'], 2));

        // 2) a manual member fx_rate wins (the user already converted): only x fx_rate, no BGN rate on top
        $manual = $this->makeGroup($companyId, ['method' => 'full', 'base_currency' => 'BGN', 'eliminate_intercompany' => false], $entityIds, $none, [$first => ['fx_rate' => 2]]);
        $rm = $service->run($manual, $this->from, $this->to);
        $bad = $this->scaledMismatches($plain, $rm, [$first => 2.0] + array_fill_keys($entityIds, $k), false);
        $this->check('ръчният курс на юр. лице има предимство пред превалутирането към груповата валута', $bad === 0, $bad . ' разминавания');

        // 3) no rate for the group currency: amount_base is kept and every converted entity is listed in fxMissing()
        DB::table('fin_entities')->whereIn('id', $entityIds)->update(['currency' => 'XTS']);
        $none3 = $this->makeGroup($companyId, ['method' => 'full', 'base_currency' => 'XTS', 'eliminate_intercompany' => false], $entityIds, $none);
        $rn = $service->run($none3, $this->from, $this->to);
        $missing = $service->fxMissing();
        $this->check('без курс фирмена → групова валута: сумите остават, юр. лицата са във fxMissing()',
            $this->scaledMismatches($plain, $rn, array_fill_keys($entityIds, 1.0), true) === 0
            && empty(array_diff($entityIds, array_keys($missing))) && count($missing) === count($entityIds)
            && empty(array_filter($missing, fn($m) => $m['currency'] !== 'EUR' || $m['group_currency'] !== 'XTS' || empty($m['date']))),
            count($missing) . ' юр. лица във fxMissing()');
    }

    /**
     * AFV (S10): number of values of $actual that differ from $expected x the factor of the entity (by_entity), per
     * line (matched by account_id). With $totals also eliminations, adjustments, minority and consolidated (same factor
     * for all entities). Tolerance 0.02 (rounding of both sides).
     *
     * @param array<int, float> $factors entity id => factor
     */
    protected function scaledMismatches(array $expected, array $actual, array $factors, bool $totals): int
    {
        $byId = array_column($actual['lines'], null, 'account_id');
        $k = reset($factors);
        $bad = 0;

        foreach ($expected['lines'] as $l) {
            $a = $byId[$l['account_id']] ?? null;

            if ($a === null) {
                $bad++;
                continue;
            }

            foreach ($l['by_entity'] as $id => $v) {
                $bad += abs(($a['by_entity'][$id] ?? 0.0) - $v * $factors[$id]) < 0.02 ? 0 : 1;
            }

            if ($totals) {
                foreach (['eliminations', 'adjustments', 'minority'] as $key) {
                    $bad += abs($a[$key] - $l[$key] * $k) < 0.02 ? 0 : 1;
                }

                $bad += abs($a['consolidated'] - $l['consolidated'] * $k) < 0.02 * (count($l['by_entity']) + 1) ? 0 : 1;
            }
        }

        return $bad;
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * Temporary group (inside the rolled back transaction).
     *
     * @param array<int, array> $memberOverrides entity id => member attributes
     */
    protected function makeGroup(int $companyId, array $attrs, array $entityIds, array $rules, array $memberOverrides = []): FinConsolidationGroup
    {
        $group = FinConsolidationGroup::withoutGlobalScope(CompanyScope::class)->create(array_merge([
            'company_id' => $companyId, 'name' => 'TMP S4 check ' . uniqid(), 'base_currency' => 'EUR',
            'method' => 'full', 'eliminate_intercompany' => true, 'minority_interest' => true,
        ], $attrs));

        foreach ($entityIds as $id) {
            FinConsolidationGroupEntity::withoutGlobalScope(CompanyScope::class)->create(array_merge([
                'company_id' => $companyId, 'group_id' => $group->id, 'entity_id' => $id, 'ownership_pct' => null, 'fx_rate' => null, 'include' => true,
            ], $memberOverrides[$id] ?? []));
        }

        foreach ($rules as $i => [$type, $params]) {
            FinConsolidationRule::withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $companyId, 'group_id' => $group->id, 'type' => $type, 'params' => $params, 'sort' => $i + 1,
            ]);
        }

        return $group->fresh();
    }

    protected function check(string $label, bool $ok, string $detail = ''): void
    {
        $ok ? $this->passed++ : $this->failed++;
        $this->line(($ok ? '  <info>OK</info>   ' : '  <error>FAIL</error> ') . $label . ($detail !== '' ? " ($detail)" : ''));
    }

    protected function findLine(array $result, string $code): ?array
    {
        foreach ($result['lines'] as $l) {
            if ($l['code'] === $code) {
                return $l;
            }
        }

        return null;
    }

    /** by_entity sums, eliminations, adjustments and consolidated of all lines of a type. */
    protected function sumType(array $result, string $type): array
    {
        $out = ['by_entity' => array_fill_keys(array_keys($result['entities']), 0.0), 'eliminations' => 0.0, 'adjustments' => 0.0, 'consolidated' => 0.0];

        foreach ($result['lines'] as $l) {
            if ($l['type'] !== $type) {
                continue;
            }

            foreach ($l['by_entity'] as $id => $v) {
                $out['by_entity'][$id] += $v;
            }

            $out['eliminations'] += $l['eliminations'];
            $out['adjustments'] += $l['adjustments'];
            $out['consolidated'] += $l['consolidated'];
        }

        return $out;
    }

    protected function elimTotal(array $result): float
    {
        return array_sum(array_column($result['lines'], 'eliminations'));
    }

    protected function allRounded(array $result): bool
    {
        foreach ($result['lines'] as $l) {
            foreach (array_merge($l['by_entity'], [$l['eliminations'], $l['adjustments'], $l['minority'], $l['consolidated']]) as $v) {
                if (!is_float($v) || abs($v - round($v, 2)) > 1e-9) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Plain SQL sum of intra-group P&L flows in the period. */
    protected function icFlowSum(array $entities, array $names): array
    {
        $rows = DB::table('fin_entries as fe')
            ->join('fin_accounts as fa', 'fa.id', '=', 'fe.account_id')
            ->join('fin_entities as e', 'e.id', '=', 'fe.entity_id')
            ->where('fe.kind', 'flow')
            ->whereIn('fe.entity_id', array_keys($entities))
            ->whereIn('fe.counterparty_name', array_values($entities))
            ->whereColumn('fe.counterparty_name', '!=', 'e.name')
            ->whereBetween('fe.entry_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->groupBy('fa.type')
            ->selectRaw('fa.type, SUM(fe.amount_base) as total')
            ->pluck('total', 'type');

        return ['revenue' => (float)($rows['revenue'] ?? 0), 'expense' => (float)($rows['expense'] ?? 0)];
    }

    /** True when the group has a rule that eliminates every intra-group P&L entry (703/602 and the rest). */
    protected function rulesEliminateFlows(FinConsolidationGroup $group): bool
    {
        foreach ($group->rules()->get() as $rule) {
            $accounts = (array)($rule->params['accounts'] ?? []);

            if ($rule->type === 'eliminate_counterparty' && (empty($accounts) || (in_array('703', $accounts) && in_array('602', $accounts)))) {
                return true;
            }

            if ($rule->type === 'eliminate_account_pair') {
                foreach ((array)($rule->params['pairs'] ?? []) as $pair) {
                    if (in_array('703', $pair) && in_array('602', $pair)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function showNumbers(array $res, array $revenue, float $elim): void
    {
        $this->line('');
        $this->line('Период: ' . $this->from->toDateString() . ' – ' . $this->to->toDateString());

        foreach ($res['entities'] as $id => $name) {
            $this->line("  Приходи $name: " . number_format($revenue['by_entity'][$id], 2));
        }

        $this->line('  Елиминации (общо, всички редове): ' . number_format($elim, 2));
        $this->line('  Елиминации на приходите: ' . number_format($revenue['eliminations'], 2));
        $this->line('  Консолидирани приходи: ' . number_format($revenue['consolidated'], 2));
        $this->line('  Баланс: активи ' . number_format($res['balance_check']['assets'], 2) . ', пасиви+капитал '
            . number_format($res['balance_check']['liabilities_equity'], 2) . ', разлика ' . number_format($res['balance_check']['difference'], 2));
    }

}
