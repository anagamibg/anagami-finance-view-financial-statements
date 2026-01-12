<?php

namespace Database\Seeders\Databackd\Demo;

use App\Models\Company;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinConsolidationGroup;
use App\Models\Databackd\FinConsolidationGroupEntity;
use App\Models\Databackd\FinConsolidationRule;
use App\Models\Databackd\FinDimension;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinRatio;
use App\Models\User;
use App\Scopes\ActiveScope;
use App\Scopes\CompanyScope;
use App\Services\Databackd\Analytics\SystemRatios;
use App\Services\Databackd\ChartOfAccounts;
use Carbon\Carbon;
use Database\Seeders\Databackd\DatabackdDemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data of the "Analytics" package (ratios and consolidation):
 *  - the system financial ratios (SystemRatios::installFor) and three custom ratios with targets;
 *  - one consolidation group with the three legal entities and three rules;
 *  - adjustments of the S1 financial model so the ratios look realistic (the S1 model piles up cash:
 *    current ratio ~8, debt ratio ~0.2, net margin of October ~29%):
 *      1. a short-term bank credit (account 498, current liability) that finances new fixed assets (203 / 204),
 *         sized per entity and month to hold the current ratio around 1.5 - 1.8;
 *      2. intra-group receivables (411) and payables (401) with the other entity as counterparty
 *         (document "ВГ-САЛДО-…"), so the balance sheet has something to eliminate in the consolidation;
 *      3. opening balances at 30.04.2025 (a copy of the first month-end), so the "average balance" ratios of
 *         May 2025 are not halved;
 *      4. accrued expenses (609) in October 2026 that pull the net margin of the month (inflated by the manual
 *         and imported entries of the Data package) back to ~18%.
 *    Every adjustment is a balance entry (assets = liabilities + equity per entity and date) with
 *    source = demo and description = "analytics demo adjust", except the accrued expenses (flow entries with
 *    document_no "АНК-…"). S1 / S2 entries are never touched.
 *
 * Idempotent: own adjustments, the consolidation group (by name) and the custom ratios (by code) are removed
 * and rebuilt; system ratios are installed only when missing.
 */
class AnalyticsDemoSeeder extends Seeder
{

    const START = '2025-05-01';
    const MONTHS = 18;

    const ADJUST = 'analytics demo adjust';
    const FLOW_DOC_PREFIX = 'АНК-';
    const GROUP_NAME = 'Демо Финанс Груп — консолидирано';

    const LOAN_CODE = '498';
    const LOAN_NAME = 'Краткосрочни банкови заеми';
    const BANK = 'Банка Балканкредит АД';

    /** Share of the monthly intra-group sales still unpaid at month end. */
    const INTRA_OPEN_SHARE = 0.6;

    /** Share of the maximal credit-financed fixed assets (the rest of the credit stays as cash). */
    const ASSET_SHARE = 0.85;

    /** Target net margin (%) of October 2026 after the accrued expenses, by entity sort. */
    const OCT_NET_MARGIN = [1 => 16.5, 2 => 19.2, 3 => 18.4];

    /** Per entity key (by sort): current ratio target at the end of the period, slow wave phase, share of 203 in the new assets. */
    const ENTITY_PARAMS = [
        1 => ['t' => 1.50, 'phase' => 0.4, 'buildings' => 0.70],
        2 => ['t' => 1.60, 'phase' => 1.9, 'buildings' => 0.60],
        3 => ['t' => 1.70, 'phase' => 3.3, 'buildings' => 0.00],
    ];

    /** Custom ratio codes (removed and rebuilt). */
    const CUSTOM_CODES = ['custom_ext_services_pct', 'custom_revenue_per_employee', 'custom_payroll_cash_cover'];

    protected int $companyId = 0;
    protected ?int $adminId = null;
    protected array $acc = [];
    protected array $rows = [];

    public function run(Company $company)
    {
        $this->companyId = $company->id;
        $this->rows = [];

        ChartOfAccounts::ensureFor($this->companyId);
        $this->acc = FinAccount::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('id', 'code')->all();
        $this->adminId = User::withoutGlobalScopes([CompanyScope::class, ActiveScope::class])
            ->where('company_id', $this->companyId)->where('email', DatabackdDemoSeeder::ADMIN_EMAIL)->value('id');

        $entities = FinEntity::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->orderBy('sort')->orderBy('id')->get();

        if ($entities->count() < 3) {
            $this->command?->warn('AnalyticsDemoSeeder: the financial model demo (FinModelDemoSeeder) must run first.');

            return;
        }

        $this->cleanup();
        $this->ensureLoanAccount();
        $this->installRatios();
        $this->customRatios();
        $this->consolidationGroup($entities);
        $this->adjustBalances($entities);
        $this->accruedExpenses($entities);

        foreach (array_chunk($this->rows, 500) as $chunk) {
            DB::table('fin_entries')->insert($chunk);
        }

        if ($this->command) {
            $this->command->info('analytics demo: ' . count($this->rows) . ' fin_entries adjustments, '
                . FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->count() . ' ratios');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Cleanup
    // ---------------------------------------------------------------------------------------------

    protected function cleanup(): void
    {
        DB::table('fin_entries')->where('company_id', $this->companyId)->where('source', 'demo')
            ->where(function ($q) {
                $q->where('description', self::ADJUST)->orWhere('document_no', 'like', self::FLOW_DOC_PREFIX . '%');
            })->delete();

        $groupIds = FinConsolidationGroup::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->where('name', self::GROUP_NAME)->pluck('id')->all();

        if ($groupIds) {
            FinConsolidationRule::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->whereIn('group_id', $groupIds)->delete();
            FinConsolidationGroupEntity::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->whereIn('group_id', $groupIds)->delete();
            FinConsolidationGroup::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->whereIn('id', $groupIds)->delete();
        }

        FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->whereIn('code', self::CUSTOM_CODES)->delete();
    }

    protected function ensureLoanAccount(): void
    {
        $account = FinAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->where('code', self::LOAN_CODE)->first();

        if (!$account) {
            $account = FinAccount::withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $this->companyId, 'code' => self::LOAN_CODE, 'name' => self::LOAN_NAME,
                'type' => 'liability', 'subtype' => 'current_liability', 'is_active' => true,
            ]);
        }

        $this->acc[self::LOAN_CODE] = $account->id;
    }

    // ---------------------------------------------------------------------------------------------
    // Ratios
    // ---------------------------------------------------------------------------------------------

    protected function installRatios(): void
    {
        SystemRatios::installForCompanyId($this->companyId);
    }

    protected function customRatios(): void
    {
        $employees = $this->employeeCount();

        $ratios = [
            [
                'code' => 'custom_ext_services_pct',
                'name' => 'Разходи за външни услуги / приходи',
                'formula' => 'FLOW[602] / TYPE[revenue] * 100',
                'format' => 'percent',
                'target_min' => null,
                'target_max' => 20,
                'higher_is_better' => false,
                'description' => 'Оборот на сметка 602 „Разходи за външни услуги“ / всички приходи, в проценти. Колко от приходите се изразходват за външни доставчици (облачни услуги, одит, реклама, куриери). Над 20 % е сигнал да се преговарят договорите с доставчиците.',
                'sort' => 91,
            ],
            [
                'code' => 'custom_revenue_per_employee',
                'name' => 'Приходи на служител (годишни)',
                'formula' => 'TYPE[revenue] * ANNUAL / ' . $employees,
                'format' => 'currency',
                'target_min' => 150000,
                'target_max' => null,
                'higher_is_better' => true,
                'description' => 'Приходи за периода, преизчислени на годишна база (ANNUAL), разделени на броя служители. Броят служители (' . $employees
                    . ') е фиксиран във формулата - взет е от демо данните (потребители с роля „служител“), защото формулите нямат достъп до служителите. При промяна на персонала редактирайте числото в края на формулата. Целта е за групата; при отделни юридически лица стойността е по-ниска, защото служителите се делят на цялата група.',
                'sort' => 92,
            ],
            [
                'code' => 'custom_payroll_cash_cover',
                'name' => 'Покритие на заплатите с наличности (месеци)',
                'formula' => 'SUB[cash] / (SUB[payroll] / DAYS * 30.4)',
                'format' => 'ratio',
                'target_min' => 3,
                'target_max' => null,
                'higher_is_better' => true,
                'description' => 'Парични средства към края на периода / средномесечните разходи за заплати и осигуровки (оборот на подтип „Персонал“ за периода, разделен на дните и умножен по 30.4). Резултатът е в месеци и не зависи от дължината на периода - за месец и за година дава сравними стойности. Под 3 месеца е нужен резерв.',
                'sort' => 93,
            ],
        ];

        foreach ($ratios as $r) {
            FinRatio::withoutGlobalScope(CompanyScope::class)->create($r + [
                'company_id' => $this->companyId, 'group' => 'custom', 'is_system' => false, 'is_active' => true,
            ]);
        }
    }

    /** Employees of the demo company: users with the "employee" role (falls back to 9). */
    protected function employeeCount(): int
    {
        $count = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('users', 'users.id', '=', 'role_user.user_id')
            ->where('users.company_id', $this->companyId)->where('roles.name', 'employee')
            ->count();

        return $count > 0 ? $count : 9;
    }

    // ---------------------------------------------------------------------------------------------
    // Consolidation group
    // ---------------------------------------------------------------------------------------------

    protected function consolidationGroup($entities): void
    {
        $group = FinConsolidationGroup::withoutGlobalScope(CompanyScope::class)->create([
            'company_id' => $this->companyId,
            'name' => self::GROUP_NAME,
            'base_currency' => 'EUR',
            'method' => 'full',
            'eliminate_intercompany' => true,
            'minority_interest' => true,
            'description' => 'Пълна консолидация на „Демо Финанс Груп АД“ с дъщерните дружества „Демо Търговия ЕООД“ (100 %) и „Демо Консулт ООД“ (70 %, с малцинствено участие). Вътрешногрупните продажби и разчети се елиминират.',
        ]);

        foreach ($entities as $entity) {
            $pct = $entity->ownership_pct !== null ? (float)$entity->ownership_pct : 100.0;

            FinConsolidationGroupEntity::withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $this->companyId, 'group_id' => $group->id, 'entity_id' => $entity->id,
                'ownership_pct' => $pct, 'fx_rate' => null, 'include' => true,
            ]);
        }

        $rules = [
            [
                'type' => 'eliminate_counterparty', 'params' => ['accounts' => ['703', '602']], 'sort' => 10,
                'description' => 'Елиминиране на вътрешногрупови продажби и покупки',
            ],
            [
                'type' => 'eliminate_account_pair', 'params' => ['pairs' => [['411', '401'], ['703', '602']]], 'sort' => 20,
                'description' => 'Взаимно изчистване на вътрешногруповите вземания и задължения (клиенти / доставчици) и на приходите и разходите по тях',
            ],
            [
                'type' => 'reclassify', 'params' => ['from' => '609', 'to' => '602'], 'sort' => 30,
                'description' => 'Прекласифициране на „Други разходи“ (609: такси, представителни и командировъчни, начислени одит и реклама) във „Външни услуги“ (602) в консолидирания отчет',
            ],
        ];

        foreach ($rules as $rule) {
            FinConsolidationRule::withoutGlobalScope(CompanyScope::class)->create($rule + [
                'company_id' => $this->companyId, 'group_id' => $group->id,
            ]);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Balance adjustments
    // ---------------------------------------------------------------------------------------------

    /**
     * For every entity and month-end: the intra-group balances (r = receivable, p = payable), the credit d,
     * new fixed assets n and extra cash c, with  n + c + r = d + p  (the entity stays balanced) and the
     * current ratio  (CA + r + c) / (CL + p + d) = target.
     */
    protected function adjustBalances($entities): void
    {
        $base = $this->baseBalances();
        $intra = $this->intraGroupOpen($entities);
        $names = $entities->pluck('name', 'id')->all();

        $rowsByEntity = [];

        foreach ($entities as $entity) {
            $params = self::ENTITY_PARAMS[(int)$entity->sort] ?? self::ENTITY_PARAMS[1];
            $series = [];

            // pass 1: maximal credit-financed assets per month
            for ($i = 0; $i < self::MONTHS; $i++) {
                $b = $base[$entity->id][$i] ?? ['ca' => 0, 'cl' => 0];
                $t = $this->target($params, $i);
                $r = $this->intraTotal($intra, $entity->id, $i, 'receivable');
                $p = $this->intraTotal($intra, $entity->id, $i, 'payable');
                $dStar = ($b['ca'] + $r) / $t - $b['cl'] - $p;
                $series[$i] = ['t' => $t, 'r' => $r, 'p' => $p, 'nMax' => max(0.0, $dStar - $r + $p)];
            }

            // fixed assets never decrease: running minimum from the future
            $floor = INF;
            for ($i = self::MONTHS - 1; $i >= 0; $i--) {
                $floor = min($floor, self::ASSET_SHARE * $series[$i]['nMax']);
                $series[$i]['n'] = $floor;
            }

            // pass 2: credit and cash
            for ($i = 0; $i < self::MONTHS; $i++) {
                $b = $base[$entity->id][$i] ?? ['ca' => 0, 'cl' => 0];
                $s = $series[$i];
                $date = Carbon::parse(self::START)->addMonths($i)->endOfMonth()->toDateString();

                $n = (int)round($s['n']);
                $d = (int)round(($b['ca'] + $s['p'] - $n - $s['t'] * ($b['cl'] + $s['p'])) / ($s['t'] - 1));
                $d = max($d, 0);
                $c = $d + (int)round($s['p']) - $n - (int)round($s['r']);

                if ($c < 0) {
                    $n += $c; // keep cash non-negative
                    $c = 0;
                }

                $rowsByEntity[$entity->id][$i] = [];
                $add = function (string $code, int $cents, array $attrs = []) use (&$rowsByEntity, $entity, $i, $date) {
                    if ($cents > 0) {
                        $rowsByEntity[$entity->id][$i][] = $this->row($entity->id, $code, $date, 'balance', $cents, $attrs + ['description' => self::ADJUST]);
                    }
                };

                $buildings = (int)round($n * $params['buildings']);
                $add(self::LOAN_CODE, $d, ['counterparty_name' => self::BANK, 'document_no' => 'КР-' . substr($date, 0, 4) . '-001']);
                $add('203', $buildings, ['document_no' => 'АКТ-' . substr($date, 0, 4) . '-01']);
                $add('204', $n - $buildings, ['document_no' => 'АКТ-' . substr($date, 0, 4) . '-02']);
                $add('503', $c, ['document_no' => 'БН-' . substr($date, 0, 7)]);

                foreach ($intra[$entity->id][$i]['receivable'] ?? [] as $buyerId => $cents) {
                    $add('411', $cents, ['counterparty_name' => $names[$buyerId], 'document_no' => 'ВГ-САЛДО-' . substr($date, 0, 7)]);
                }

                foreach ($intra[$entity->id][$i]['payable'] ?? [] as $sellerId => $cents) {
                    $add('401', $cents, ['counterparty_name' => $names[$sellerId], 'document_no' => 'ВГ-САЛДО-' . substr($date, 0, 7)]);
                }
            }
        }

        foreach ($rowsByEntity as $months) {
            foreach ($months as $monthRows) {
                foreach ($monthRows as $row) {
                    $this->rows[] = $row;
                }
            }
        }

        $this->openingBalances($entities, $rowsByEntity);
    }

    /**
     * Opening balances at the day before the first month: the first month-end copied (S1 balances + the adjustments),
     * so AVG(...) in the first month is not distorted by a missing opening balance.
     */
    protected function openingBalances($entities, array $rowsByEntity): void
    {
        $first = Carbon::parse(self::START)->endOfMonth()->toDateString();
        $opening = Carbon::parse(self::START)->subDay()->toDateString();

        $s1 = DB::table('fin_entries')->where('company_id', $this->companyId)->where('kind', 'balance')->where('source', 'demo')
            ->where('entry_date', $first)->where('description', '!=', self::ADJUST)->get();

        foreach ($s1 as $e) {
            $this->rows[] = $this->row($e->entity_id, $this->codeOf((int)$e->account_id), $opening, 'balance', (int)round($e->amount * 100), [
                'description' => self::ADJUST, 'counterparty_name' => $e->counterparty_name, 'document_no' => 'ОС-' . $opening,
            ]);
        }

        foreach ($rowsByEntity as $months) {
            foreach ($months[0] ?? [] as $row) {
                $copy = $row;
                $copy['entry_date'] = $opening;
                $this->rows[] = $copy;
            }
        }
    }

    /**
     * Current assets (cash, receivables, inventory) and current liabilities of every entity at the end of each
     * month in cents, from the S1 balance entries.
     *
     * @return array<int, array<int, array{ca: int, cl: int}>> entity id => month index => values
     */
    protected function baseBalances(): array
    {
        $start = Carbon::parse(self::START);
        $monthEnds = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $monthEnds[$start->copy()->addMonths($i)->endOfMonth()->toDateString()] = $i;
        }

        $rows = DB::table('fin_entries as e')
            ->join('fin_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.company_id', $this->companyId)->where('e.kind', 'balance')->where('e.source', 'demo')
            ->whereIn('e.entry_date', array_keys($monthEnds))
            ->selectRaw('e.entity_id, e.entry_date, a.subtype, SUM(e.amount) as amount')
            ->groupBy('e.entity_id', 'e.entry_date', 'a.subtype')
            ->get();

        $out = [];

        foreach ($rows as $r) {
            $i = $monthEnds[Carbon::parse($r->entry_date)->toDateString()];
            $cents = (int)round($r->amount * 100);
            $out[$r->entity_id][$i] ??= ['ca' => 0, 'cl' => 0];

            if (in_array($r->subtype, ['cash', 'receivable', 'inventory'], true)) {
                $out[$r->entity_id][$i]['ca'] += $cents;
            } elseif ($r->subtype === 'current_liability') {
                $out[$r->entity_id][$i]['cl'] += $cents;
            }
        }

        return $out;
    }

    /**
     * Open intra-group balances at month end, from the intra-group sales of the month (revenue 703, document ВГ-…).
     *
     * @return array<int, array<int, array{receivable: array<int, int>, payable: array<int, int>}>> entity id => month => (counterparty entity id => cents)
     */
    protected function intraGroupOpen($entities): array
    {
        $byName = $entities->pluck('id', 'name')->all();
        $start = Carbon::parse(self::START);
        $rows = DB::table('fin_entries as e')
            ->join('fin_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.company_id', $this->companyId)->where('e.kind', 'flow')->where('e.source', 'demo')
            ->where('a.code', '703')->where('e.document_no', 'like', 'ВГ-%')->where('e.document_no', 'not like', 'ВГ-САЛДО-%')
            ->selectRaw('e.entity_id, e.counterparty_name, DATE_FORMAT(e.entry_date, "%Y-%m") as ym, SUM(e.amount) as amount')
            ->groupBy('e.entity_id', 'e.counterparty_name', 'ym')
            ->get();

        $out = [];

        foreach ($rows as $r) {
            $buyer = $byName[$r->counterparty_name] ?? null;
            $month = Carbon::parse($r->ym . '-01');
            $i = (int)round($start->diffInMonths($month, false));

            if (!$buyer || $i < 0 || $i >= self::MONTHS) {
                continue;
            }

            $cents = (int)round($r->amount * 100 * self::INTRA_OPEN_SHARE);

            if ($cents <= 0) {
                continue;
            }

            $out[$r->entity_id][$i]['receivable'][$buyer] = ($out[$r->entity_id][$i]['receivable'][$buyer] ?? 0) + $cents;
            $out[$buyer][$i]['payable'][$r->entity_id] = ($out[$buyer][$i]['payable'][$r->entity_id] ?? 0) + $cents;
        }

        return $out;
    }

    protected function intraTotal(array $intra, int $entityId, int $month, string $side): int
    {
        return array_sum($intra[$entityId][$month][$side] ?? []);
    }

    /** Target current ratio of the entity in month i (falls slowly, with a small wave). */
    protected function target(array $params, int $i): float
    {
        return $params['t'] + 0.20 * (self::MONTHS - 1 - $i) / (self::MONTHS - 1) + 0.06 * sin(1.9 * $i + $params['phase']);
    }

    // ---------------------------------------------------------------------------------------------
    // Accrued expenses in October 2026
    // ---------------------------------------------------------------------------------------------

    protected function accruedExpenses($entities): void
    {
        $costCenters = FinDimension::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->where('type', 'cost_center')->pluck('id', 'name')->all();
        $from = '2026-10-01';
        $to = '2026-10-31';
        $date = '2026-10-30';

        $totals = DB::table('fin_entries as e')
            ->join('fin_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.company_id', $this->companyId)->where('e.kind', 'flow')
            ->whereBetween('e.entry_date', [$from, $to])->whereIn('a.type', ['revenue', 'expense'])
            ->selectRaw('e.entity_id, a.type, SUM(e.amount_base) as amount')
            ->groupBy('e.entity_id', 'a.type')->get()
            ->groupBy('entity_id');

        $seq = 0;

        foreach ($entities as $entity) {
            $sums = ($totals[$entity->id] ?? collect())->pluck('amount', 'type');
            $revenue = (float)($sums['revenue'] ?? 0);
            $expense = (float)($sums['expense'] ?? 0);
            $needed = (int)round(($revenue * (1 - (self::OCT_NET_MARGIN[(int)$entity->sort] ?? 18.0) / 100) - $expense) * 100);

            if ($needed < 10000) {
                continue;
            }

            $audit = (int)round($needed * 0.6);
            $parts = [
                ['Администрация', $audit, 'Начислен одит, правно и счетоводно обслужване – октомври 2026'],
                ['Продажби', $needed - $audit, 'Начислена рекламна кампания и участие в изложение – октомври 2026'],
            ];

            foreach ($parts as [$cc, $cents, $text]) {
                $seq++;
                $this->rows[] = $this->row($entity->id, '609', $date, 'flow', $cents, [
                    'cost_center_id' => $costCenters[$cc] ?? null,
                    'counterparty_name' => 'Кабинет Одит и Право ЕООД',
                    'document_no' => self::FLOW_DOC_PREFIX . '2026-10-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT),
                    'description' => $text,
                ]);
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    protected function codeOf(int $accountId): string
    {
        static $flip = null;
        $flip = array_flip($this->acc);

        return (string)$flip[$accountId];
    }

    protected function row(int $entityId, string $code, string $date, string $kind, int $cents, array $attrs): array
    {
        $now = now()->toDateTimeString();

        return array_merge([
            'company_id' => $this->companyId,
            'entity_id' => $entityId,
            'account_id' => $this->acc[$code],
            'entry_date' => $date,
            'kind' => $kind,
            'amount' => number_format($cents / 100, 2, '.', ''),
            'currency' => 'EUR',
            'amount_base' => number_format($cents / 100, 2, '.', ''),
            'region_id' => null, 'client_segment_id' => null, 'product_group_id' => null, 'cost_center_id' => null,
            'client_id' => null, 'counterparty_name' => null, 'document_no' => null, 'description' => null,
            'source' => 'demo', 'import_batch_id' => null, 'created_by' => $this->adminId,
            'created_at' => $now, 'updated_at' => $now,
        ], $attrs);
    }

}
