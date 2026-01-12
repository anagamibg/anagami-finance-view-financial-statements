<?php

namespace App\Console\Commands\Databackd;

use App\Models\Company;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinRatio;
use App\Scopes\CompanyScope;
use App\Services\Databackd\Analytics\FormulaEngine;
use App\Services\Databackd\Analytics\RatioCalculator;
use App\Services\Databackd\Analytics\SystemRatios;
use App\Services\Databackd\FinancialDataService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * databackd: self-check of the formula engine, the ratio calculator and the system ratios on the demo company.
 * Prints one line per check and exits with a non-zero code when any check fails. Temporary data is created
 * inside a transaction that is always rolled back.
 */
class CheckFormulas extends Command
{

    protected $signature = 'databackd:check-formulas {--company= : Company name or id (default: the first company named "Демо Финанс Груп")}';

    protected $description = 'Check the FormulaEngine, RatioCalculator and the system ratios against the demo data';

    protected int $passed = 0;
    protected int $failed = 0;
    protected int $companyId;
    protected FinancialDataService $svc;

    public function handle(): int
    {
        $company = $this->findCompany();

        if (!$company) {
            $this->error('Company not found (use --company=<name or id>).');

            return self::FAILURE;
        }

        $this->companyId = (int)$company->id;
        $this->svc = new FinancialDataService($this->companyId);
        $this->info('Company #' . $this->companyId . ' ' . $company->company_name);

        // The system ratios must exist for the company (idempotent, same as the first open of the dashboard)
        $created = SystemRatios::installFor($company);
        $this->line("System ratios installed now: $created");

        $e = new FormulaEngine($this->companyId);
        $from = Carbon::parse('2026-10-01');
        $to = Carbon::parse('2026-10-31');

        $this->section('Operators and syntax', function () use ($e, $from, $to) {
            $this->checkArithmetic($e, $from, $to);
        });
        $this->section('Variables against FinancialDataService', function () use ($e, $from, $to) {
            $this->checkVariables($e, $from, $to);
        });
        $this->section('Division by zero, validation, resolver, explain', function () use ($e, $from, $to) {
            $this->checkErrors($e, $from, $to);
        });
        $this->section('REF[] and SystemRatios::installFor (rolled back)', function () use ($from, $to) {
            $this->checkRefsAndInstall($from, $to);
        });
        $this->section('System ratios give numbers (Oct 2026 and 2026, every entity and all together)', function () use ($from, $to) {
            $this->checkSystemRatios($from, $to);
        });
        $this->section('Functions, negative denominators, targets and locale format (S12)', function () use ($e, $from, $to) {
            $this->checkFunctionsAndFormat($e, $from, $to);
        });
        $this->section('Analytic accounts: other type under a synthetic, contra analytics, revenue balances (review A, rolled back)', function () use ($from, $to) {
            $this->checkSubaccounts($from, $to);
        });

        $this->newLine();
        $total = $this->passed + $this->failed;

        if ($this->failed) {
            $this->error("FAILED: {$this->failed} of $total checks failed.");

            return self::FAILURE;
        }

        $this->info("OK: all $total checks passed.");

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Sections
    // ---------------------------------------------------------------------------------------------------------

    protected function checkArithmetic(FormulaEngine $e, Carbon $from, Carbon $to): void
    {
        $cases = [
            ['1+2', 3.0], ['7-10', -3.0], ['6*7', 42.0], ['10/4', 2.5],
            ['1+2*3', 7.0], ['2*3+1', 7.0], ['10-4-3', 3.0], ['100/10/5', 2.0],
            ['(1+2)*3', 9.0], ['2*(3+4)*(1+1)', 28.0], ['((2))', 2.0],
            ['-5+2', -3.0], ['2*-3', -6.0], ['-(2+3)', -5.0], ['2-(-3)', 5.0], ['-2*3', -6.0],
            ['1.5*2', 3.0],
            ['DAYS', 31.0], ['DAYS * ANNUAL', 365.0],
        ];

        foreach ($cases as [$formula, $expected]) {
            $actual = $this->safeEval($e, $formula, $from, $to);
            $this->assert($this->near($actual, $expected, 1e-6), "$formula = " . $this->fmt($expected), 'got ' . $this->fmt($actual));
        }
    }

    protected function checkVariables(FormulaEngine $e, Carbon $from, Carbon $to): void
    {
        $svc = $this->svc;
        $prev = $from->copy()->subDay();
        $codes = fn(array $subtypes) => FinAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->where('is_active', true)->whereIn('subtype', $subtypes)->pluck('code')->map(fn($c) => (string)$c)->all();
        // the engine reads every listed account on its own (review A: no sub-account expansion in formulas)
        $bal = fn($codes, $date = null) => $svc->balanceAt($date ?? $to, $codes, [], false);

        $cash = $bal(['503']);
        $this->assertNear('ACC[503] = cash balance at period end', $this->safeEval($e, 'ACC[503]', $from, $to), $cash);

        $this->assertNear('ACC[50*] = ACC[501] + ACC[503]', $this->safeEval($e, 'ACC[50*]', $from, $to), $bal(['501']) + $bal(['503']));
        $this->assertNear('ACC[50*] = sum of separate accounts via the engine', $this->safeEval($e, 'ACC[50*]', $from, $to),
            $this->safeEval($e, 'ACC[501] + ACC[503]', $from, $to));

        $pnl = $svc->pnl($from, $to);
        $byCode = [];

        foreach (array_merge($pnl['revenue'], $pnl['expense']) as $row) {
            $byCode[$row['code']] = $row['amount'];
        }

        $this->assertNear('FLOW[703] = turnover of 703 (pnl)', $this->safeEval($e, 'FLOW[703]', $from, $to), $byCode['703'] ?? 0);
        $this->assertNear('FLOW[70*] = turnover of all 70x accounts', $this->safeEval($e, 'FLOW[70*]', $from, $to),
            array_sum(array_filter($byCode, fn($v, $k) => str_starts_with((string)$k, '70'), ARRAY_FILTER_USE_BOTH)));
        $this->assertNear('ACC[703] on a revenue account is a period turnover = FLOW[703]', $this->safeEval($e, 'ACC[703]', $from, $to), $byCode['703'] ?? 0);
        $this->assertNear('TYPE[revenue] = pnl revenue', $this->safeEval($e, 'TYPE[revenue]', $from, $to), $pnl['totals']['revenue']);
        $this->assertNear('TYPE[expense] = pnl expense', $this->safeEval($e, 'TYPE[expense]', $from, $to), $pnl['totals']['expense']);
        $this->assertNear('TYPE[revenue] - TYPE[expense] = pnl profit', $this->safeEval($e, 'TYPE[revenue] - TYPE[expense]', $from, $to), $pnl['totals']['profit']);

        $cashCodes = $codes(['cash']);
        $this->assertNear('SUB[cash] = balance of cash accounts', $this->safeEval($e, 'SUB[cash]', $from, $to), $bal($cashCodes));
        $this->assertNear('SUB[current_asset] = cash + receivable + inventory',
            $this->safeEval($e, 'SUB[current_asset]', $from, $to), $bal($codes(['cash', 'receivable', 'inventory'])));
        $this->assertNear('SUB[current_liability] = balance of current liability accounts',
            $this->safeEval($e, 'SUB[current_liability]', $from, $to), $bal($codes(['current_liability'])));
        $this->assertNear('TYPE[asset] = balance of all asset accounts (contra 241 subtracted)',
            $this->safeEval($e, 'TYPE[asset]', $from, $to), $svc->balanceAt($to, ['asset']));

        $receivable = $codes(['receivable']);
        $this->assertNear('AVG(ACC[411]) = (balance at start + balance at end) / 2', $this->safeEval($e, 'AVG(ACC[411])', $from, $to),
            ($bal(['411'], $prev) + $bal(['411'])) / 2);
        $this->assertNear('AVG(SUB[receivable]) = average of start and end', $this->safeEval($e, 'AVG(SUB[receivable])', $from, $to),
            ($bal($receivable, $prev) + $bal($receivable)) / 2);
        $this->assertNear('AVG(TYPE[asset]) = average of start and end', $this->safeEval($e, 'AVG(TYPE[asset])', $from, $to),
            ($svc->balanceAt($prev, ['asset']) + $svc->balanceAt($to, ['asset'])) / 2);

        $entities = $this->entityIds();

        if ($entities) {
            $filters = ['entity_ids' => [$entities[0]]];
            $this->assertNear('entity filter: ACC[503] of the first entity', $this->safeEval($e, 'ACC[503]', $from, $to, $filters), $svc->balanceAt($to, ['503'], $filters, false));
            $this->assertNear('entity filter: TYPE[revenue] of the first entity', $this->safeEval($e, 'TYPE[revenue]', $from, $to, $filters),
                $svc->pnl($from, $to, $filters)['totals']['revenue']);
        }

        $q = Carbon::parse('2026-07-01');
        $this->assertNear('another period: FLOW[70*] for Q3 2026 = pnl', $this->safeEval($e, 'FLOW[70*]', $q, Carbon::parse('2026-09-30')),
            array_sum(array_map(fn($r) => str_starts_with((string)$r['code'], '70') ? $r['amount'] : 0, $svc->pnl($q, Carbon::parse('2026-09-30'))['revenue'])));
    }

    protected function checkErrors(FormulaEngine $e, Carbon $from, Carbon $to): void
    {
        // Division by zero gives null (and everything depending on it)
        foreach (['1/0', '1/(2-2)', '1 + 1/0', '5 * (3/0)', 'ACC[503] / (ACC[503] - ACC[503])'] as $f) {
            $this->assert($this->safeEval($e, $f, $from, $to) === null, "$f = null (division by zero)");
        }

        // Invalid formulas
        $invalid = ['' => 'empty formula', '   ' => 'blank formula', '1+' => '1+', '(1+2' => '(1+2', '1+2)' => '1+2)', 'ACC[999]' => 'ACC[999] unknown account',
            'FOO[1]' => 'FOO[1] unknown kind', '1 2' => '1 2 (missing operator)', 'AVG(REF[x])' => 'AVG(REF[x])', '*3' => '*3', '()' => '()',
            'TYPE[foo]' => 'TYPE[foo] unknown type', 'SUB[foo]' => 'SUB[foo] unknown subtype', 'ACC[]' => 'ACC[] empty argument', '1 $ 2' => '1 $ 2 (illegal character)',
            'ACC[9*]' => 'ACC[9*] prefix without accounts'];

        foreach ($invalid as $formula => $label) {
            $errors = $e->validate((string)$formula);
            $this->assert(count($errors) > 0, "validate rejects: $label", $errors ? $errors[0] : 'no error returned');
        }

        foreach (['1+2', 'ACC[50*]', '-FLOW[703]', 'AVG(SUB[receivable]) / FLOW[70*] * DAYS', '(TYPE[revenue] - TYPE[expense]) / TYPE[revenue] * 100 * ANNUAL'] as $formula) {
            $errors = $e->validate($formula);
            $this->assert($errors === [], "validate accepts: $formula", $errors ? $errors[0] : '');
        }

        // evaluate() throws on a broken formula (RatioCalculator turns it into null)
        $thrown = false;

        try {
            $e->evaluate('1+', $from, $to);
        } catch (\InvalidArgumentException $ex) {
            $thrown = true;
        }

        $this->assert($thrown, 'evaluate("1+") throws InvalidArgumentException');

        // Resolver replaces the value of a variable; null falls back to the database
        $resolver = fn(string $kind, string $arg, bool $avg) => $kind === 'ACC' && $arg === '503' && !$avg ? 1000.0 : null;
        $this->assertNear('resolver replaces ACC[503]', $e->evaluate('ACC[503] + 1', $from, $to, [], $resolver), 1001);
        $this->assertNear('resolver returning null falls back to the data', $e->evaluate('TYPE[revenue]', $from, $to, [], $resolver),
            $this->svc->pnl($from, $to)['totals']['revenue']);
        $this->assertNear('resolver sees AVG flag and period', $e->evaluate('AVG(ACC[503]) + DAYS', $from, $to, [],
            fn($kind, $arg, $avg, $f, $t) => $avg ? 100.0 + $f->diffInDays($t) * 0 : null), 131);

        // explain(): a value for every variable
        $x = $e->explain('ACC[503] + FLOW[703] / DAYS + AVG(SUB[receivable])', $from, $to);
        $this->assert($x['error'] === null && $x['value'] !== null, 'explain: formula value is present', (string)$x['error']);
        $this->assert(count($x['variables']) === 3, 'explain: one row per distinct variable', 'rows: ' . count($x['variables']));
        $this->assert(count(array_filter($x['variables'], fn($v) => $v['value'] === null || $v['label'] === '')) === 0, 'explain: every variable has a value and a label');
        $sum = array_sum(array_column($x['variables'], 'value'));
        $flow703 = collect($x['variables'])->firstWhere('key', 'FLOW[703]')['value'] ?? 0;
        $this->assertNear('explain: value is consistent with variable values',
            $x['value'], $sum - $flow703 + $flow703 / 31);

        $broken = $e->explain('1+', $from, $to);
        $this->assert($broken['value'] === null && $broken['error'] !== null, 'explain: broken formula returns an error and no value');

        $v = $e->variables('ACC[501] + ACC[501] + AVG(SUB[cash]) * REF[x]');
        $this->assert(count($v) === 3, 'variables(): distinct variables only', 'got ' . count($v));
    }

    protected function checkRefsAndInstall(Carbon $from, Carbon $to): void
    {
        DB::beginTransaction();

        try {
            // Start from the clean state of the system ratios (installed above), temporary ratios only inside the transaction
            $make = fn(string $code, string $formula) => FinRatio::withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $this->companyId, 'code' => $code, 'name' => $code, 'group' => 'custom', 'formula' => $formula,
                'format' => 'ratio', 'higher_is_better' => true, 'is_system' => false, 'is_active' => true, 'sort' => 999,
            ]);

            $make('tmp_a', 'ACC[503] * 2');
            $make('tmp_b', 'REF[tmp_a] + 1');
            $make('tmp_c', 'REF[tmp_b] / 2');
            $e = new FormulaEngine($this->companyId);
            $cash = $this->svc->balanceAt($to, ['503']);

            $this->assertNear('REF[tmp_a] = its own formula', $this->safeEval($e, 'REF[tmp_a]', $from, $to), $cash * 2);
            $this->assertNear('REF[tmp_b] (nested REF) = 2 * cash + 1', $this->safeEval($e, 'REF[tmp_b]', $from, $to), $cash * 2 + 1);
            $this->assertNear('REF in an expression: REF[tmp_c] * 2 - REF[tmp_a]', $this->safeEval($e, 'REF[tmp_c] * 2 - REF[tmp_a]', $from, $to), 1);
            $this->assert($e->validate('REF[tmp_a] + 1', 'tmp_x') === [], 'validate accepts an existing REF');
            $this->assert($e->validate('REF[nope_zzz]') !== [], 'validate rejects an unknown REF');
            $this->assert($e->validate('REF[tmp_a]', 'tmp_a') !== [], 'validate rejects a REF to the ratio itself');

            // Cycle: tmp_a -> tmp_b -> tmp_c; editing tmp_a to point at tmp_c must be refused
            $errors = (new FormulaEngine($this->companyId))->validate('REF[tmp_c] + 1', 'tmp_a');
            $this->assert($errors !== [], 'validate rejects a cyclic REF (tmp_a -> tmp_c -> tmp_b -> tmp_a)', $errors ? $errors[0] : 'no error');

            // Runtime cycle protection
            FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', 'tmp_a')->update(['formula' => 'REF[tmp_c] + 1']);
            $thrown = false;

            try {
                (new FormulaEngine($this->companyId))->evaluate('REF[tmp_a]', $from, $to);
            } catch (\InvalidArgumentException $ex) {
                $thrown = true;
            }

            $this->assert($thrown, 'evaluate throws on a cyclic REF instead of looping');

            // RatioCalculator turns the same error into null
            $calc = new RatioCalculator(null, $this->companyId);
            $ratio = FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', 'tmp_b')->first();
            $this->assert($calc->calculate($ratio, $from, $to) === null, 'RatioCalculator::calculate returns null for a cyclic ratio');

            // SystemRatios::installFor is idempotent and keeps client edits
            $company = Company::find($this->companyId);
            $this->assert(SystemRatios::installFor($company) === 0, 'installFor: second call creates nothing');

            $current = FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', 'current_ratio')->first();
            $current->update(['target_min' => 9.9, 'is_active' => false]);
            SystemRatios::installForCompanyId($this->companyId);
            $current->refresh();
            $this->assert((float)$current->target_min === 9.9 && !$current->is_active, 'installFor: keeps edited target_min / is_active');

            FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', 'cash_ratio')->delete();
            $this->assert(SystemRatios::installForCompanyId($this->companyId) === 1, 'installFor: recreates exactly the missing ratio');

            $systemCount = FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('is_system', true)->count();
            $this->assert($systemCount >= count(SystemRatios::definitions()), 'all ' . count(SystemRatios::definitions()) . ' system ratios exist', "found $systemCount");

            $bad = [];
            $engine = new FormulaEngine($this->companyId);

            foreach (SystemRatios::definitions() as $code => $d) {
                $errors = $engine->validate($d['formula'], $code);

                if ($errors) {
                    $bad[] = "$code: " . $errors[0];
                }

                if (!in_array($d['group'], FinRatio::GROUPS, true) || !in_array($d['format'], FinRatio::FORMATS, true)) {
                    $bad[] = "$code: bad group/format";
                }
            }

            $this->assert($bad === [], 'every system formula validates; groups and formats are valid', implode('; ', $bad));
        } finally {
            DB::rollBack();
        }

        $left = FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', 'like', 'tmp\_%')->count();
        $this->assert($left === 0, 'temporary ratios are rolled back', "$left left");

        $current = FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', 'current_ratio')->first();
        $this->assert($current && (float)$current->target_min === (float)SystemRatios::DEFINITIONS['current_ratio']['target_min'] && $current->is_active,
            'edits of the test are rolled back');
    }

    protected function checkSystemRatios(Carbon $from, Carbon $to): void
    {
        $periods = [
            'Oct 2026' => [$from, $to],
            '2026' => [Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')],
        ];

        $scopes = ['all' => []];

        foreach (FinEntity::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->orderBy('id')->get() as $entity) {
            $scopes[$entity->name] = ['entity_ids' => [$entity->id]];
        }

        $ratios = FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)
            ->where('is_system', true)->orderBy('sort')->get();
        $calc = new RatioCalculator(null, $this->companyId);
        $table = [];

        foreach ($ratios as $ratio) {
            $nulls = [];
            $shown = null;

            foreach ($periods as $pLabel => [$pf, $pt]) {
                foreach ($scopes as $sLabel => $filters) {
                    $value = $calc->calculate($ratio, $pf, $pt, $filters);

                    if ($value === null) {
                        $nulls[] = "$pLabel/$sLabel";
                    }

                    if ($pLabel === 'Oct 2026' && $sLabel === 'all') {
                        $shown = $value;
                    }
                }
            }

            $table[] = [$ratio->code, $ratio->format, $shown];
            $this->assert($nulls === [], sprintf('%-20s Oct 2026 / all = %s', $ratio->code, RatioCalculator::format($ratio, $shown)),
                'null for: ' . implode(', ', $nulls));
        }

        $this->assert(count($ratios) >= 15, 'at least 15 system ratios are installed', 'found ' . count($ratios));

        // RatioCalculator: series and status
        $first = $ratios->firstWhere('code', 'current_ratio');

        if ($first) {
            $series = $calc->series($first, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), 'month');
            $this->assert(count($series) === 12, 'series(month) over 2026 has 12 points', 'got ' . count($series));
            $this->assert(in_array($calc->status($first, 1.5), ['good', 'warning', 'bad', 'none'], true) && $calc->status($first, 1.5) === 'good', 'status(current_ratio, 1.5) = good');
            $this->assert($calc->status($first, null) === 'none', 'status(null) = none');
        }

        $this->newLine();
        $this->line('Values for Oct 2026, all legal entities together:');

        foreach ($table as [$code, $format, $value]) {
            $this->line(sprintf('  %-20s %-9s %s', $code, $format, $value === null ? 'null' : $value));
        }
    }

    protected function checkFunctionsAndFormat(FormulaEngine $e, Carbon $from, Carbon $to): void
    {
        // Functions and precedence around them
        foreach ([['ABS(-3)', 3.0], ['MIN(4, 2, 9)', 2.0], ['MAX(1, 2*3)', 6.0], ['POS(5) * 2', 10.0], ['-MAX(1, 2)', -2.0],
                     ['2 * -MIN(3, 4)', -6.0], ['abs(1 - 3) + 1', 3.0], ['MAX(ABS(-2), MIN(1, 5)) / 2', 1.0]] as [$f, $expected]) {
            $this->assert($this->near($this->safeEval($e, $f, $from, $to), $expected, 1e-9), "$f = " . $this->fmt($expected), 'got ' . $this->fmt($this->safeEval($e, $f, $from, $to)));
        }

        foreach (['POS(0)', 'POS(-1)', '1 / POS(-5)', 'MIN(1, 1/0)'] as $f) {
            $this->assert($this->safeEval($e, $f, $from, $to) === null, "$f = null (not calculated)");
        }

        foreach (['MIN(1)' => 'MIN with one value', 'ABS(1, 2)' => 'ABS with two values', 'ABS 3' => 'function without brackets',
                     '1,5' => 'decimal comma', 'MIN(1,)' => 'empty function argument', 'POS()' => 'empty brackets', '(1, 2)' => 'comma outside a function'] as $f => $label) {
            $errors = $e->validate($f);
            $this->assert($errors !== [], "validate rejects: $label", 'no error returned');
        }

        // Error positions count characters, not bytes (a Cyrillic letter before the error)
        $errors = $e->validate('ACC[501] + ФОО');
        $this->assert($errors && str_contains($errors[0], '12'), 'error position counts characters', $errors[0] ?? 'no error');

        // A negative equity: ROE and debt / equity are not calculated instead of turning "good"
        $negativeEquity = fn(string $kind, string $arg) => $kind === 'TYPE' && $arg === 'equity' ? -50000.0 : null;
        $calc = new RatioCalculator($e);

        foreach (['roe', 'debt_to_equity'] as $code) {
            $ratio = FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', $code)->first();
            $this->assert($ratio && $calc->calculate($ratio, $from, $to, [], $negativeEquity) === null, "$code with a negative equity = null",
                $ratio ? 'got ' . $this->fmt($calc->calculate($ratio, $from, $to, [], $negativeEquity)) : 'ratio missing');
        }

        $this->assert(SystemRatios::syncForCompanyId($this->companyId) === 0, 'syncFor: system formulas are up to date (second sync changes nothing)');

        // Target logic: "higher is better" and "lower is better", inside / better / worse
        $hi = new FinRatio(['format' => 'ratio', 'target_min' => 1.0, 'target_max' => 2.0, 'higher_is_better' => true]);
        $lo = new FinRatio(['format' => 'ratio', 'target_min' => 1.0, 'target_max' => 2.0, 'higher_is_better' => false]);
        $cases = [
            [$hi, 1.5, 'good', 'in'], [$hi, 3.0, 'good', 'better'], [$hi, 0.9, 'warning', 'worse'], [$hi, 0.5, 'bad', 'worse'],
            [$lo, 1.5, 'good', 'in'], [$lo, 0.5, 'good', 'better'], [$lo, 2.2, 'warning', 'worse'], [$lo, 3.0, 'bad', 'worse'],
        ];

        foreach ($cases as [$r, $value, $status, $position]) {
            $label = ($r->higher_is_better ? 'higher' : 'lower') . " is better, target 1-2, value $value";
            $this->assert($calc->status($r, $value) === $status && $calc->position($r, $value) === $position, "$label: $status / $position",
                'got ' . $calc->status($r, $value) . ' / ' . $calc->position($r, $value));
        }

        // Locale format: bg 1,60 / 12,4 % / 1 234,50 / −0,25; en 1.60 / 1,234.50
        $locale = app()->getLocale();
        $nb = RatioCalculator::NBSP;

        try {
            app()->setLocale('bg');
            $ratio = new FinRatio(['format' => 'ratio']);
            $percent = new FinRatio(['format' => 'percent']);
            $this->assert(RatioCalculator::format($ratio, 1.6) === '1,60', 'format bg ratio 1.6 = 1,60', RatioCalculator::format($ratio, 1.6));
            $this->assert(RatioCalculator::format($percent, 12.44) === "12,4{$nb}%", 'format bg percent 12.44 = 12,4 %', RatioCalculator::format($percent, 12.44));
            $this->assert(RatioCalculator::format($ratio, 1234.5) === "1{$nb}234,50", 'format bg 1234.5 = 1 234,50', RatioCalculator::format($ratio, 1234.5));
            $this->assert(RatioCalculator::format($ratio, -0.25) === '−0,25', 'format bg -0.25 = −0,25', RatioCalculator::format($ratio, -0.25));
            $this->assert(RatioCalculator::format($ratio, -0.001) === '0,00', 'format bg -0.001 = 0,00 (no "−0,00")', RatioCalculator::format($ratio, -0.001));
            $this->assert(RatioCalculator::formatChange($percent, 15.0, 12.5) === "+2,5{$nb}п.п.", 'change bg percent = +2,5 п.п.', (string)RatioCalculator::formatChange($percent, 15.0, 12.5));
            app()->setLocale('eng');
            $this->assert(RatioCalculator::format($ratio, 1234.5) === '1,234.50', 'format en 1234.5 = 1,234.50', RatioCalculator::format($ratio, 1234.5));
        } finally {
            app()->setLocale($locale);
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Review A: an active analytic account under a passive synthetic one (4531 under 453), an analytic account of the contra
     * 241 (2411) and a revenue account with a balance entry. Temporary accounts / entries inside a rolled back transaction.
     */
    protected function checkSubaccounts(Carbon $from, Carbon $to): void
    {
        $entity = $this->entityIds()[0] ?? null;
        $account = fn(string $code) => FinAccount::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', $code)->first();

        if (!$entity || !$account('453') || !$account('241') || !$account('411') || !$account('703')) {
            $this->assert(false, 'demo data: an entity and the accounts 453, 241, 411, 703 exist');

            return;
        }

        DB::beginTransaction();

        try {
            $svc = new FinancialDataService($this->companyId);
            $day = $to->toDateString();
            $make = fn(string $code, string $type, ?string $subtype, string $parent) => FinAccount::withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $this->companyId, 'code' => $code, 'name' => 'check ' . $code, 'type' => $type, 'subtype' => $subtype,
                'parent_id' => $account($parent)->id, 'is_active' => true,
            ]);
            $entry = fn(string $code, string $kind, string $date, float $amount) => DB::table('fin_entries')->insert([
                'company_id' => $this->companyId, 'entity_id' => $entity, 'account_id' => $account($code)->id, 'entry_date' => $date,
                'kind' => $kind, 'amount' => $amount, 'currency' => 'EUR', 'amount_base' => $amount, 'source' => 'manual',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $before = [
                '453' => $svc->balanceAt($to, '453'), '241' => $svc->balanceAt($to, '241'), '411' => $svc->balanceAt($to, '411'),
                'liability' => (new FormulaEngine($this->companyId))->evaluate('TYPE[liability]', $from, $to),
                'asset' => (new FormulaEngine($this->companyId))->evaluate('TYPE[asset]', $from, $to),
                'acc411' => (new FormulaEngine($this->companyId))->evaluate('ACC[411]', $from, $to),
            ];
            $cashflow = fn() => (new \App\Services\Databackd\Reports\CashflowStatement($this->companyId))
                ->build(\App\Services\Databackd\Reports\ReportFilters::fromArray(['from' => $from->toDateString(), 'to' => $day, 'periodicity' => 'month'], $this->companyId))['kpi'];
            $cfBefore = $cashflow();

            $make('4531', 'asset', 'current_asset', '453'); // VAT to recover (active) under VAT payable (passive)
            $make('2411', 'asset', 'non_current_asset', '241');
            $make('4111', 'asset', 'receivable', '411');
            $entry('4531', 'balance', $day, 1000);
            $entry('2411', 'balance', $day, 500);
            $entry('4111', 'balance', $day, 300);

            $e = new FormulaEngine($this->companyId);
            $this->assertNear('balanceAt(453) does not take the active analytic 4531', $svc->balanceAt($to, '453'), $before['453']);
            $this->assertNear('TYPE[liability] does not count the active 4531', $e->evaluate('TYPE[liability]', $from, $to), $before['liability']);
            $this->assertNear('TYPE[asset] counts 4531 once and subtracts 2411 (+1000 - 500 + 300)', $e->evaluate('TYPE[asset]', $from, $to), $before['asset'] + 800);
            $this->assertNear('balanceAt(241) subtracts the contra analytic 2411', $svc->balanceAt($to, '241'), $before['241'] - 500);
            $this->assertNear('balanceAt(2411) alone is negative (contra)', $svc->balanceAt($to, '2411'), -500);
            $this->assertNear('balanceAt(411) takes the analytic 4111 (same type)', $svc->balanceAt($to, '411'), $before['411'] + 300);
            $this->assertNear('ACC[411] in a formula stays 411 only', $e->evaluate('ACC[411]', $from, $to), $before['acc411']);
            $this->assertNear('ACC[411] + ACC[4111] counts 4111 once', $e->evaluate('ACC[411] + ACC[4111]', $from, $to), $before['acc411'] + 300);
            $this->assertNear('ACC[411*] takes the analytic accounts', $e->evaluate('ACC[411*]', $from, $to), $e->evaluate('ACC[411] + ACC[4111]', $from, $to));
            $this->assert(FinAccount::isContraCode('2411') && FinAccount::isContraCode('241-1') && FinAccount::isContraCode('A1', '241')
                && !FinAccount::isContraCode('411') && !FinAccount::isContraCode('24'), 'FinAccount::isContraCode: 2411, 241-1, A1 under 241 yes; 411, 24 no');

            $cf = $cashflow();
            // 2411 (+500 accumulated depreciation) reduces the assets: +500 in the investing activity, 4531 / 4111 (+1300) -1300 in operating
            $this->assertNear('cash flow: contra 2411 counted as depreciation (investing +500)', $cf['investing'], $cfBefore['investing'] + 500);

            $cfOperating = $cf['operating'];
            $entry('703', 'balance', $from->copy()->addDays(14)->toDateString(), 2000);
            $this->assertNear('cash flow: a balance entry of a revenue account does not change the operating activity', $cashflow()['operating'], $cfOperating);
        } finally {
            DB::rollBack();
        }
    }

    protected function findCompany(): ?Company
    {
        $option = $this->option('company');

        if ($option !== null && $option !== '') {
            return ctype_digit((string)$option)
                ? Company::find((int)$option)
                : Company::where('company_name', $option)->orderBy('id')->first();
        }

        return Company::where('company_name', 'Демо Финанс Груп')->orderBy('id')->first();
    }

    /** @return int[] */
    protected function entityIds(): array
    {
        return FinEntity::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->orderBy('id')->pluck('id')->all();
    }

    protected function safeEval(FormulaEngine $e, string $formula, Carbon $from, Carbon $to, array $filters = []): ?float
    {
        try {
            return $e->evaluate($formula, $from, $to, $filters);
        } catch (\Throwable $ex) {
            return null;
        }
    }

    protected function section(string $title, callable $callback): void
    {
        $this->newLine();
        $this->line("== $title");

        try {
            $callback();
        } catch (\Throwable $ex) {
            $this->assert(false, "section aborted by an exception: $title", get_class($ex) . ': ' . $ex->getMessage() . ' (' . basename($ex->getFile()) . ':' . $ex->getLine() . ')');
        }
    }

    protected function assert(bool $ok, string $label, string $detail = ''): void
    {
        if ($ok) {
            $this->passed++;
            $this->line("  <info>✔</info> $label");
        } else {
            $this->failed++;
            $this->line("  <error>✘</error> $label" . ($detail !== '' ? "  [$detail]" : ''));
        }
    }

    protected function assertNear(string $label, ?float $actual, float $expected): void
    {
        $this->assert($this->near($actual, $expected, 0.01), $label . ' = ' . $this->fmt($expected), 'got ' . $this->fmt($actual));
    }

    protected function near(?float $a, float $b, float $tolerance): bool
    {
        return $a !== null && abs($a - $b) <= $tolerance;
    }

    protected function fmt(?float $v): string
    {
        return $v === null ? 'null' : rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }

}
