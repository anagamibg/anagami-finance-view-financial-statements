<?php

namespace App\Services\Databackd\Analytics;

use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinRatio;
use App\Scopes\CompanyScope;
use App\Services\Databackd\FinancialDataService;
use Carbon\Carbon;

/**
 * Evaluates financial ratio formulas without eval(): a tokenizer plus a shunting-yard parser.
 *
 * A formula is an arithmetic expression with the operators + - * / ( ), unary minus, numbers and variables:
 *
 *   ACC[501]        balance of account 501 at the end of the period (P&L accounts: turnover for the period)
 *   ACC[50*]        the same for every account whose code starts with 50
 *   FLOW[703]       turnover of the account for the period (also FLOW[70*])
 *   TYPE[revenue]   all accounts of a type: asset|liability|equity|revenue|expense (balance or flow by type)
 *   SUB[cash]       all accounts of a subtype (current_asset also includes cash, receivable and inventory; non_current_asset, cash, receivable, inventory,
 *                   current_liability, non_current_liability, cogs, payroll, opex, depreciation, interest, tax, other)
 *   AVG(ACC[411])   average of the balance at the start and at the end of the period (works with ACC, TYPE, SUB)
 *   REF[code]       the value of another ratio of the company
 *   DAYS            number of days in the period
 *   ANNUAL          365 / DAYS, used to annualise a ratio calculated for a month or a quarter
 *
 * Functions (names are case-insensitive; arguments are expressions separated by commas):
 *   ABS(x)          absolute value
 *   MIN(a, b, ...)  the smallest / MAX(a, b, ...) the largest of two or more values
 *   POS(x)          x when it is greater than 0, otherwise "cannot be calculated" (null) - for denominators such as the
 *                   equity: a loss divided by a negative equity must not show up as a positive ROE
 *
 * Division by zero (and anything that depends on it) gives null.
 *
 * Error messages and variable labels are translated (databackd-analytics-builder.engine.*).
 *
 * Values come from FinancialDataService, or from the optional $resolver:
 *   function (string $kind, string $arg, bool $avg, Carbon $from, Carbon $to, array $filters): ?float
 * A non-null result replaces the database value of that variable (used for consolidated data and in checks).
 */
class FormulaEngine
{

    const KINDS = ['ACC', 'FLOW', 'TYPE', 'SUB', 'REF'];
    const CONSTANTS = ['DAYS', 'ANNUAL'];
    /** name => [minimum, maximum] number of arguments (null = no maximum) */
    const FUNCTIONS = ['ABS' => [1, 1], 'POS' => [1, 1], 'MIN' => [2, null], 'MAX' => [2, null]];
    const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];
    const SUBTYPES = ['current_asset', 'non_current_asset', 'cash', 'receivable', 'inventory', 'current_liability',
        'non_current_liability', 'cogs', 'payroll', 'opex', 'depreciation', 'interest', 'tax', 'other'];

    /**
     * Hard limits (S6/P3). They apply when a formula is saved (validate) AND every time a stored formula is calculated,
     * so a formula that was saved before the limits existed, or written straight to the database, cannot exhaust the server.
     * The 109 built-in checks use at most 91 characters, 15 tokens, 6 variables, nesting 1 and no REF chain.
     */
    const MAX_LENGTH = 1000;      // characters of one formula
    const MAX_TOKENS = 400;       // numbers, operators, brackets and variables
    const MAX_VARIABLES = 30;     // variable tokens in one formula
    const MAX_NESTING = 20;       // depth of the brackets
    const MAX_NUMBER_LENGTH = 20; // characters of a numeric literal
    const MAX_REF_DEPTH = 8;      // REF[a] -> REF[b] -> ... chain
    const MAX_EVALUATIONS = 400;  // variable look-ups (including the ones of REF formulas) for one evaluate()/explain()

    protected ?int $companyId;
    protected ?FinancialDataService $data = null;
    protected ?array $accounts = null;
    protected ?array $refCodes = null;
    protected array $memo = [];
    protected array $refStack = [];
    protected int $evaluations = 0;

    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId;
    }

    public function companyId(): int
    {
        return $this->companyId ??= company()->id;
    }

    /**
     * Formula syntax and references check.
     *
     * @return string[] error messages in Bulgarian, empty when the formula is valid
     */
    public function validate(string $formula, ?string $selfCode = null): array
    {
        if (trim($formula) === '') {
            return [self::msg('empty')];
        }

        [$tokens, $errors] = $this->tokenize($formula);

        if ($errors) {
            return $errors;
        }

        $errors = $this->checkGrammar($tokens);

        if ($errors) {
            return $errors;
        }

        $errors = [];

        foreach ($tokens as $t) {
            if ($t['t'] !== 'var') {
                continue;
            }

            $error = $this->checkVariable($t, $selfCode);

            if ($error) {
                $errors[] = $error;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Distinct variables used in the formula.
     *
     * @return array<int, array{key: string, kind: string, arg: string, avg: bool}>
     */
    public function variables(string $formula): array
    {
        [$tokens] = $this->tokenize($formula);
        $out = [];

        foreach ($tokens as $t) {
            if ($t['t'] === 'var') {
                $out[$t['key']] = ['key' => $t['key'], 'kind' => $t['kind'], 'arg' => $t['arg'], 'avg' => $t['avg']];
            }
        }

        return array_values($out);
    }

    /**
     * @param callable|null $resolver see the class description
     */
    public function evaluate(string $formula, Carbon $from, Carbon $to, array $filters = [], ?callable $resolver = null): ?float
    {
        $values = null;
        $this->evaluations = 0;

        return $this->run($formula, $from, $to, $filters, $resolver, $values);
    }

    /**
     * The value of the formula and of every variable in it (for the "formula components" table).
     *
     * @return array{value: ?float, error: ?string, variables: array<int, array{key: string, label: string, kind: string, arg: string, avg: bool, value: ?float}>}
     */
    public function explain(string $formula, Carbon $from, Carbon $to, array $filters = [], ?callable $resolver = null): array
    {
        $values = [];
        $error = null;
        $this->evaluations = 0;

        try {
            $value = $this->run($formula, $from, $to, $filters, $resolver, $values);
        } catch (\InvalidArgumentException $e) {
            $value = null;
            $error = $e->getMessage(); // our own messages (texts of this class only)
        } catch (\Throwable $e) {
            // a database / runtime error: its text (SQL, bindings, paths) goes to the log, not to the browser
            report($e);
            $value = null;
            $error = self::msg('cannot_calculate');
        }

        $rows = [];

        foreach ($this->variables($formula) as $v) {
            $rows[] = $v + ['label' => $this->label($v), 'value' => $values[$v['key']] ?? null];
        }

        return ['value' => $value, 'error' => $error, 'variables' => $rows];
    }

    /** Human readable name of a variable, in the language of the user. */
    public function label(array $v): string
    {
        $kind = $v['kind'];
        $arg = $v['arg'];
        $l = fn(string $key, array $r = []) => __('databackd-analytics-builder.engine.labels.' . $key, $r);

        if ($kind === 'REF') {
            $ratio = $this->ratioByCode($arg);

            return $l('ref', ['name' => $ratio?->name ?? $arg]);
        }

        if ($kind === 'TYPE' || $kind === 'SUB') {
            $name = $kind === 'TYPE'
                ? __('databackd-analytics-common.account_types.' . $arg)
                : __('databackd-analytics-common.account_subtypes.' . $arg);

            return $l(($v['avg'] ? 'avg_' : '') . strtolower($kind), ['name' => $name]);
        }

        // ACC / FLOW
        if (str_ends_with($arg, '*')) {
            $what = $l('accounts_prefix', ['code' => $arg]);
        } else {
            $name = collect($this->resolveAccounts($kind, $arg))->pluck('name', 'code')->get($arg);
            $what = $name ? $l('account_named', ['code' => $arg, 'name' => $name]) : $l('account', ['code' => $arg]);
        }

        if ($kind === 'FLOW') {
            return $l('flow', ['what' => $what]);
        }

        return $l($v['avg'] ? 'avg_balance' : 'balance', ['what' => $what]);
    }

    /** Translated message of the formula engine (databackd-analytics-builder.engine.*). */
    protected static function msg(string $key, array $replace = []): string
    {
        return __('databackd-analytics-builder.engine.' . $key, $replace);
    }

    /** 1-based position of the byte offset $i in characters (a Cyrillic letter before it is one character, not two bytes). */
    protected static function position(string $s, int $i): int
    {
        return mb_strlen(substr($s, 0, $i)) + 1;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Tokenizer and parser
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @return array{0: array<int, array>, 1: string[]} tokens and errors
     */
    protected function tokenize(string $s): array
    {
        $tokens = [];
        $errors = [];
        $i = 0;
        $n = strlen($s);

        // Checked first: the loop below copies the rest of the string for every token (quadratic on a long formula)
        if ($n > self::MAX_LENGTH) {
            return [[], [self::msg('too_long', ['max' => self::MAX_LENGTH])]];
        }

        while ($i < $n) {
            $c = $s[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }

            if (count($tokens) >= self::MAX_TOKENS) {
                $errors[] = self::msg('too_complex', ['max' => self::MAX_TOKENS]);
                return [$tokens, $errors];
            }

            $rest = substr($s, $i);

            if (preg_match('/^\d+(?:\.\d+)?/', $rest, $m)) {
                if (strlen($m[0]) > self::MAX_NUMBER_LENGTH) {
                    $errors[] = self::msg('number_too_long', ['max' => self::MAX_NUMBER_LENGTH, 'pos' => self::position($s, $i)]);
                    return [$tokens, $errors];
                }

                $tokens[] = ['t' => 'num', 'v' => (float)$m[0], 'pos' => self::position($s, $i)];
                $i += strlen($m[0]);
            } elseif (preg_match('/^AVG\s*\(\s*([A-Za-z]+)\s*\[([^\]]*)\]\s*\)/i', $rest, $m)) {
                $tokens[] = $this->variableToken($m[1], $m[2], true);
                $i += strlen($m[0]);
            } elseif (preg_match('/^([A-Za-z]+)\s*\[([^\]]*)\]/', $rest, $m)) {
                $tokens[] = $this->variableToken($m[1], $m[2], false);
                $i += strlen($m[0]);
            } elseif (preg_match('/^[A-Za-z_]+/', $rest, $m)) {
                $name = strtoupper($m[0]);
                $opensBracket = (bool)preg_match('/^\s*\(/', substr($rest, strlen($m[0])));

                if (in_array($name, self::CONSTANTS, true)) {
                    $tokens[] = ['t' => 'const', 'name' => $name];
                } elseif (isset(self::FUNCTIONS[$name])) {
                    if (!$opensBracket) {
                        $errors[] = self::msg('function_brackets', ['name' => $name]);
                        return [$tokens, $errors];
                    }

                    $tokens[] = ['t' => 'func', 'name' => $name];
                } elseif ($name === 'AVG') {
                    $errors[] = self::msg('avg_usage');
                    return [$tokens, $errors];
                } else {
                    $errors[] = self::msg('unknown_word', ['word' => self::safeText($m[0]), 'pos' => self::position($s, $i)]);
                    return [$tokens, $errors];
                }

                $i += strlen($m[0]);
            } elseif (in_array($c, ['+', '-', '*', '/'], true)) {
                $tokens[] = ['t' => 'op', 'v' => $c];
                $i++;
            } elseif ($c === '(' || $c === ')') {
                $tokens[] = ['t' => $c];
                $i++;
            } elseif ($c === ',') {
                $tokens[] = ['t' => ',', 'pos' => self::position($s, $i)];
                $i++;
            } else {
                // $c is one BYTE: a multibyte character (e.g. Cyrillic) would make the message invalid UTF-8 and the JSON response fail
                $char = preg_match('/^./us', $rest, $mc) ? $mc[0] : '?';
                $errors[] = self::msg('bad_char', ['char' => preg_match('/^[^<>&\p{C}\s]$/u', $char) ? $char : '?', 'pos' => self::position($s, $i)]);
                return [$tokens, $errors];
            }
        }

        return [$tokens, $errors];
    }

    /**
     * Text taken from the formula, for an error message. The messages end up in JSON and in the HTML of form errors
     * (froiden-helper inserts them with .append(html)), so only letters, digits and . _ - * [ ] are kept, the rest becomes "?".
     */
    protected static function safeText(string $text): string
    {
        $text = mb_substr($text, 0, 40);

        return preg_replace('/[^\p{L}\p{N}._*\-]/u', '?', $text) ?? '?';
    }

    protected function variableToken(string $kind, string $arg, bool $avg): array
    {
        $kind = strtoupper($kind);
        $arg = trim($arg);
        $arg = $kind === 'ACC' || $kind === 'FLOW' ? $arg : strtolower($arg);
        $key = ($avg ? 'AVG(' : '') . $kind . '[' . $arg . ']' . ($avg ? ')' : '');

        return ['t' => 'var', 'kind' => $kind, 'arg' => $arg, 'avg' => $avg, 'key' => $key];
    }

    /**
     * Operand / operator order and parentheses.
     *
     * @return string[]
     */
    protected function checkGrammar(array $tokens): array
    {
        $variables = 0;
        $expectOperand = true;
        // one frame per open bracket: the function it belongs to (null = plain brackets) and its number of arguments
        $frames = [];
        $afterFunction = false;

        foreach ($tokens as $t) {
            if ($afterFunction && $t['t'] !== '(') {
                return [self::msg('function_brackets', ['name' => $afterFunction])];
            }

            switch ($t['t']) {
                case 'num':
                case 'var':
                case 'const':
                    if (!$expectOperand) {
                        return [self::msg('missing_operator')];
                    }

                    if ($t['t'] === 'var' && ++$variables > self::MAX_VARIABLES) {
                        return [self::msg('too_many_variables', ['max' => self::MAX_VARIABLES])];
                    }

                    $expectOperand = false;
                    break;
                case 'func':
                    if (!$expectOperand) {
                        return [self::msg('missing_operator_before', ['name' => $t['name']])];
                    }

                    $afterFunction = $t['name'];
                    break;
                case 'op':
                    if ($expectOperand) {
                        if ($t['v'] === '-') {
                            break; // unary minus
                        }

                        return [self::msg('operator_no_left', ['op' => $t['v']])];
                    }

                    $expectOperand = true;
                    break;
                case '(':
                    if (!$expectOperand) {
                        return [self::msg('missing_operator_before', ['name' => '('])];
                    }

                    if (count($frames) >= self::MAX_NESTING) {
                        return [self::msg('nesting', ['max' => self::MAX_NESTING])];
                    }

                    $frames[] = ['func' => $afterFunction ?: null, 'args' => 1];
                    $afterFunction = false;
                    break;
                case ',':
                    $frame = end($frames);

                    if (!$frame || !$frame['func']) {
                        return [self::msg('comma_outside', ['pos' => $t['pos']])];
                    }

                    if ($expectOperand) {
                        return [self::msg('empty_argument', ['name' => $frame['func']])];
                    }

                    $frames[count($frames) - 1]['args']++;
                    $expectOperand = true;
                    break;
                case ')':
                    if (!$frames) {
                        return [self::msg('unmatched_close')];
                    }

                    if ($expectOperand) {
                        return [self::msg('empty_brackets')];
                    }

                    $frame = array_pop($frames);

                    if ($frame['func']) {
                        [$min, $max] = self::FUNCTIONS[$frame['func']];

                        if ($frame['args'] < $min || ($max !== null && $frame['args'] > $max)) {
                            return [self::msg($max === 1 ? 'function_one_argument' : 'function_two_arguments', ['name' => $frame['func']])];
                        }
                    }

                    break;
            }
        }

        if ($afterFunction) {
            return [self::msg('function_brackets', ['name' => $afterFunction])];
        }

        if ($expectOperand) {
            return [self::msg('ends_with_operator')];
        }

        if ($frames) {
            return [self::msg('unclosed')];
        }

        return [];
    }

    /**
     * Shunting-yard: infix tokens to reverse polish notation. Unary minus becomes the operator 'neg'.
     */
    protected function toRpn(array $tokens): array
    {
        $prec = ['neg' => 3, '*' => 2, '/' => 2, '+' => 1, '-' => 1];
        $out = [];
        $stack = [];
        $argc = []; // arguments of the open brackets (only used for function brackets)
        $prev = null;

        foreach ($tokens as $t) {
            if (in_array($t['t'], ['num', 'var', 'const'], true)) {
                $out[] = $t;
            } elseif ($t['t'] === 'func') {
                $stack[] = $t;
            } elseif ($t['t'] === 'op') {
                $unary = $t['v'] === '-' && ($prev === null || in_array($prev['t'], ['op', '(', ','], true));
                $op = $unary ? 'neg' : $t['v'];

                while ($stack) {
                    $top = end($stack);

                    if ($top['t'] === 'op' && $op !== 'neg' && $prec[$top['v']] >= $prec[$op]) {
                        $out[] = array_pop($stack);
                    } else {
                        break;
                    }
                }

                $stack[] = ['t' => 'op', 'v' => $op];
            } elseif ($t['t'] === '(') {
                $stack[] = $t;
                $argc[] = 1;
            } elseif ($t['t'] === ',') {
                while ($stack && end($stack)['t'] !== '(') {
                    $out[] = array_pop($stack);
                }

                $argc[count($argc) - 1]++;
            } else { // ')'
                while ($stack && end($stack)['t'] !== '(') {
                    $out[] = array_pop($stack);
                }

                array_pop($stack);
                $count = array_pop($argc);

                if ($stack && end($stack)['t'] === 'func') {
                    $out[] = array_pop($stack) + ['argc' => $count];
                }
            }

            $prev = $t;
        }

        while ($stack) {
            $out[] = array_pop($stack);
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Evaluation
    // ---------------------------------------------------------------------------------------------------------

    protected function run(string $formula, Carbon $from, Carbon $to, array $filters, ?callable $resolver, ?array &$values): ?float
    {
        [$tokens, $errors] = $this->tokenize($formula);

        if (!$errors) {
            $errors = $this->checkGrammar($tokens);
        }

        if ($errors) {
            throw new \InvalidArgumentException($errors[0]);
        }

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $days = max(1, $from->diffInDays($to) + 1);
        $stack = [];

        foreach ($this->toRpn($tokens) as $t) {
            if ($t['t'] === 'num') {
                $stack[] = $t['v'];
            } elseif ($t['t'] === 'const') {
                $stack[] = $t['name'] === 'DAYS' ? (float)$days : 365 / $days;
            } elseif ($t['t'] === 'var') {
                $v = $this->variableValue($t, $from, $to, $filters, $resolver);
                $values[$t['key']] = $v;
                $stack[] = $v;
            } elseif ($t['t'] === 'func') {
                $args = array_splice($stack, -$t['argc']);
                $stack[] = self::callFunction($t['name'], $args);
            } elseif ($t['v'] === 'neg') {
                $a = array_pop($stack);
                $stack[] = $a === null ? null : -$a;
            } else {
                $b = array_pop($stack);
                $a = array_pop($stack);

                if ($a === null || $b === null) {
                    $stack[] = null;
                    continue;
                }

                switch ($t['v']) {
                    case '+':
                        $r = $a + $b;
                        break;
                    case '-':
                        $r = $a - $b;
                        break;
                    case '*':
                        $r = $a * $b;
                        break;
                    default:
                        $r = abs($b) < 1e-12 ? null : $a / $b;
                }

                // Overflow (INF) or INF * 0 (NAN) is "cannot be calculated", like a division by zero: INF/NAN cannot be
                // stored in a decimal column or encoded as JSON
                $stack[] = $r === null || !is_finite($r) ? null : $r;
            }
        }

        $result = array_pop($stack);

        return $result === null || !is_finite($result) ? null : round($result, 6);
    }

    /** @param array<int, ?float> $args */
    protected static function callFunction(string $name, array $args): ?float
    {
        if (in_array(null, $args, true)) {
            return null;
        }

        switch ($name) {
            case 'ABS':
                return abs($args[0]);
            case 'POS':
                return $args[0] > 0 ? $args[0] : null;
            case 'MIN':
                return min($args);
            default: // MAX
                return max($args);
        }
    }

    protected function variableValue(array $v, Carbon $from, Carbon $to, array $filters, ?callable $resolver): ?float
    {
        // REF[a] = REF[b] + REF[b] ... can multiply the work exponentially (the value cache is off when a resolver is used)
        if (++$this->evaluations > self::MAX_EVALUATIONS) {
            throw new \InvalidArgumentException(self::msg('too_complex_evaluation'));
        }

        if ($resolver) {
            $r = $resolver($v['kind'], $v['arg'], $v['avg'], $from->copy(), $to->copy(), $filters);

            if ($r !== null) {
                return (float)$r;
            }
        }

        $memoKey = md5(json_encode([$v['key'], $from->toDateString(), $to->toDateString(), $filters, (bool)$resolver]));

        if (array_key_exists($memoKey, $this->memo) && !$resolver) {
            return $this->memo[$memoKey];
        }

        $value = $v['kind'] === 'REF'
            ? $this->refValue($v['arg'], $from, $to, $filters, $resolver)
            : $this->dataValue($v, $from, $to, $filters);

        if (!$resolver) {
            $this->memo[$memoKey] = $value;
        }

        return $value;
    }

    protected function refValue(string $code, Carbon $from, Carbon $to, array $filters, ?callable $resolver): ?float
    {
        $ratio = $this->ratioByCode($code);

        if (!$ratio) {
            throw new \InvalidArgumentException(self::msg('unknown_ref', ['code' => self::safeText($code)]));
        }

        if (in_array($code, $this->refStack, true)) {
            throw new \InvalidArgumentException(self::msg('cycle_evaluation', ['code' => self::safeText($code)]));
        }

        if (count($this->refStack) >= self::MAX_REF_DEPTH) {
            throw new \InvalidArgumentException(self::msg('ref_chain', ['max' => self::MAX_REF_DEPTH]));
        }

        $this->refStack[] = $code;

        try {
            $unused = null;

            return $this->run($ratio->formula, $from, $to, $filters, $resolver, $unused);
        } finally {
            array_pop($this->refStack);
        }
    }

    protected function dataValue(array $v, Carbon $from, Carbon $to, array $filters): float
    {
        $accounts = $this->resolveAccounts($v['kind'], $v['arg']);

        if (!$accounts) {
            return 0.0;
        }

        $service = $this->service();
        $sum = 0.0;
        $balanceCodes = [];
        $flowIds = [];

        foreach ($accounts as $a) {
            $isPnl = in_array($a['type'], ['revenue', 'expense'], true);

            if ($v['kind'] === 'FLOW' || $isPnl) {
                $flowIds[] = $a['id'];
            } else {
                $balanceCodes[] = (string)$a['code'];
            }
        }

        if ($balanceCodes) {
            // review A: without sub-accounts - resolveAccounts() already lists every account of the variable (ACC[50*] takes
            // the analytic ones, ACC[503] only 503). Expanding here would silently change saved formulas such as
            // ACC[411] + ACC[4111] (4111 twice) and add an active 4531 to TYPE[liability] through the passive 453.
            $end = $service->balanceAt($to, $balanceCodes, $filters, false);

            if ($v['avg']) {
                $start = $service->balanceAt($from->copy()->subDay(), $balanceCodes, $filters, false);
                $end = ($start + $end) / 2;
            }

            $sum += $end;
        }

        if ($flowIds) {
            $sum += (float)$service->query($filters)
                ->where('fin_entries.kind', 'flow')
                ->whereIn('fin_entries.account_id', $flowIds)
                ->whereBetween('fin_entries.entry_date', [$from->toDateString(), $to->toDateString()])
                ->sum('fin_entries.amount_base');
        }

        return round($sum, 2);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Reference data
    // ---------------------------------------------------------------------------------------------------------

    protected function checkVariable(array $t, ?string $selfCode): ?string
    {
        $kind = $t['kind'];
        $arg = $t['arg'];

        if (!in_array($kind, self::KINDS, true)) {
            return self::msg('unknown_kind', ['kind' => self::safeText($kind), 'kinds' => implode(', ', self::KINDS)]);
        }

        if ($arg === '') {
            return self::msg('empty_variable', ['kind' => $kind]);
        }

        if ($t['avg'] && !in_array($kind, ['ACC', 'TYPE', 'SUB'], true)) {
            return self::msg('avg_kinds');
        }

        switch ($kind) {
            case 'ACC':
            case 'FLOW':
                if (!preg_match('/^[A-Za-z0-9._-]+\*?$/', $arg)) {
                    return self::msg('bad_account', ['code' => self::safeText($arg)]);
                }

                if (!$this->resolveAccounts($kind, $arg)) {
                    return str_ends_with($arg, '*')
                        ? self::msg('no_prefix_accounts', ['prefix' => rtrim($arg, '*'), 'key' => $t['key']])
                        : self::msg('unknown_account', ['code' => $arg, 'key' => $t['key']]);
                }

                return null;
            case 'TYPE':
                return in_array($arg, self::TYPES, true) ? null : self::msg('unknown_type', ['type' => self::safeText($arg), 'types' => implode(', ', self::TYPES)]);
            case 'SUB':
                return in_array($arg, self::SUBTYPES, true) ? null : self::msg('unknown_subtype', ['subtype' => self::safeText($arg), 'subtypes' => implode(', ', self::SUBTYPES)]);
            default:
                if ($selfCode !== null && $arg === $selfCode) {
                    return self::msg('self_ref', ['code' => self::safeText($arg)]);
                }

                if (!$this->ratioByCode($arg)) {
                    return self::msg('unknown_ref', ['code' => self::safeText($arg)]);
                }

                $cycle = $this->refCycle($arg, $selfCode);

                if ($cycle) {
                    return self::msg('cycle', ['code' => self::safeText($arg)]);
                }

                // the chain below this reference plus the reference itself must stay within the limit (calculation enforces it too)
                return $this->refDepth($arg) > self::MAX_REF_DEPTH
                    ? self::msg('ref_chain', ['max' => self::MAX_REF_DEPTH])
                    : null;
        }
    }

    /**
     * Whether REF[$code] leads back to $selfCode. Every ratio is visited once ($visited), so a ratio set shaped as a
     * diamond ladder (a -> b1, b2; b1, b2 -> c ...) does not take exponential time.
     */
    protected function refCycle(string $code, ?string $selfCode, array &$visited = []): bool
    {
        if ($selfCode === null || isset($visited[$code])) {
            return false;
        }

        $visited[$code] = true;
        $ratio = $this->ratioByCode($code);

        if (!$ratio) {
            return false;
        }

        foreach ($this->variables($ratio->formula) as $v) {
            if ($v['kind'] !== 'REF') {
                continue;
            }

            if ($v['arg'] === $selfCode || $this->refCycle($v['arg'], $selfCode, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Length of the longest REF chain that starts at the ratio $code: 1 = the ratio has no REF in its formula, 2 = it
     * points to a ratio without REF ... Stops (and returns MAX_REF_DEPTH + 1) when the limit is exceeded or on a loop.
     */
    protected function refDepth(string $code, array &$memo = [], int $level = 0): int
    {
        if (isset($memo[$code])) {
            return $memo[$code];
        }

        if ($level > self::MAX_REF_DEPTH) {
            return self::MAX_REF_DEPTH + 1;
        }

        $memo[$code] = self::MAX_REF_DEPTH + 1; // a loop counts as too deep while the ratio is being measured
        $ratio = $this->ratioByCode($code);
        $depth = 1;

        if ($ratio) {
            foreach ($this->variables($ratio->formula) as $v) {
                if ($v['kind'] === 'REF') {
                    $depth = max($depth, 1 + $this->refDepth($v['arg'], $memo, $level + 1));
                }
            }
        }

        return $memo[$code] = min($depth, self::MAX_REF_DEPTH + 1);
    }

    /**
     * @return array<int, array{id: int, code: string, name: string, type: string, subtype: ?string}>
     */
    public function resolveAccounts(string $kind, string $arg): array
    {
        $out = [];

        foreach ($this->allAccounts() as $a) {
            if ($kind === 'TYPE') {
                $match = $a['type'] === $arg;
            } elseif ($kind === 'SUB') {
                $match = $a['subtype'] === $arg
                    || ($arg === 'current_asset' && in_array($a['subtype'], ['cash', 'receivable', 'inventory'], true));
            } elseif (str_ends_with($arg, '*')) {
                $match = str_starts_with($a['code'], rtrim($arg, '*'));
            } else {
                $match = $a['code'] === $arg;
            }

            if ($match) {
                $out[] = $a;
            }
        }

        return $out;
    }

    protected function allAccounts(): array
    {
        return $this->accounts ??= FinAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId())
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'subtype'])
            ->map(fn($a) => ['id' => $a->id, 'code' => (string)$a->code, 'name' => $a->name, 'type' => $a->type, 'subtype' => $a->subtype])
            ->all();
    }

    protected function ratioByCode(string $code): ?FinRatio
    {
        // array_key_exists: an unknown code (null) is cached too, otherwise every occurrence in a long formula is a query
        if (!array_key_exists($code, $this->refCodes ?? [])) {
            $this->refCodes[$code] = FinRatio::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->companyId())
                ->where('code', $code)
                ->first();
        }

        return $this->refCodes[$code];
    }

    protected function service(): FinancialDataService
    {
        return $this->data ??= new FinancialDataService($this->companyId());
    }

}
