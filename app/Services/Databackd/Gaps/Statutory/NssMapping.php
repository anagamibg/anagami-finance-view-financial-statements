<?php

namespace App\Services\Databackd\Gaps\Statutory;

/**
 * Explicit, documented mapping of the chart of accounts (App\Services\Databackd\ChartOfAccounts::ACCOUNTS, plus the
 * optional 498) to the rows of the simplified annual financial statements by the Bulgarian accounting standard
 * NSS 1 (balance sheet and income statement by nature of expense, micro/small enterprises form).
 *
 * An account is placed on a row by the first matching rule:
 *   1. BY_CODE    - the explicit tables BALANCE_BY_CODE / INCOME_BY_CODE below (the 29 standard accounts + 498);
 *   2. BY_PREFIX  - (a) the synthetic account of an analytic account: the first three digits of the code (5031, 503-1)
 *                   or, for a code without them, of the nearest parent account (key "synthetic" of the account array,
 *                   filled by StatutoryReport), looked up in the same tables; (b) the NSS class of the synthetic
 *                   account (first two digits), e.g. 21x = intangible assets, 62x = financial expenses;
 *   3. BY_SUBTYPE - fin_accounts.type / subtype of an account added by the user;
 *   4. FALLBACK   - nothing matches: the amount goes to the "Other" row of its side (X rows of the balance sheet,
 *                   "Other expenses" / "Other income" of the income statement) and the screen shows a warning.
 *
 * Printed codes follow the balance sheet form of the Accounting Act / NSS 1 for small and micro enterprises:
 * assets А (subscribed capital unpaid), Б (non-current), В (current), Г (prepaid expenses); equity and liabilities
 * А (equity: I capital, II share premium, III revaluation reserve, IV reserves, V retained result of previous
 * years, VI result of the current year), Б (provisions), В (liabilities), Г (financing and deferred income). The
 * row keys (asset.A = non-current assets, ...) are internal and did not change when the printed codes were aligned.
 *
 * Standard accounts and rows (codes of the rows: A, B, C, D are Latin placeholders, displayed as the letters of the
 * NSS form - see displayCode()):
 *
 *   Balance sheet - assets
 *     A.II.1  Land and buildings                      201, 203
 *     A.II.2  Machinery, equipment and other          204   (+ other non-current assets by subtype)
 *     A.II.3  Accumulated depreciation (reduces)      241   (contra account: shown as a negative amount)
 *     B.I.1   Materials                               302
 *     B.I.2   Goods                                   304
 *     B.II.1  Trade receivables                       411
 *     B.IV.1  Cash in hand                            501
 *     B.IV.2  Bank accounts                           503
 *     A.I, A.III, A.IV, B.III, C  - no account in the standard chart (rows are shown with zero, accounts are mapped
 *                                   by the NSS class of the code or by subtype when the user adds them).
 *   Balance sheet - liabilities and equity
 *     A.I     Subscribed capital                      101
 *     A.III.1 Retained result (previous years)        121   (121 holds the closing result incl. the current year -
 *                                                           see StatutoryReport: current year is split off)
 *     A.III.2 Result of the current year              = income statement C.5
 *     C.I.1   Long-term bank loans                    151
 *     C.II.1  Short-term bank loans                   498
 *     C.II.2  Suppliers                               401
 *     C.II.3  Personnel and social security           421, 454
 *     C.II.4  Taxes                                   453 (VAT), 455 (income tax withheld)
 *
 *   Income statement (by nature of expense)
 *     A.1.a Raw materials and consumables 601   A.1.b External services 602   A.2.a Salaries 604
 *     A.2.b Social security 605   A.3 Depreciation 603   A.5 Other expenses 609   A.6 Financial expenses 621
 *     B.1.a Production (own products, licences and subscriptions) 704   B.1.b Goods 702   B.1.c Services 703
 *     B.2 Other income 709   B.3 Financial income 721
 *     A.4 (carrying amount of assets sold) has no account in the chart. Profit tax (C.4) is read from expense
 *     accounts with the subtype "tax"; without such accounts it is an estimate (StatutoryReport::TAX_RATE).
 */
class NssMapping
{

    const BY_CODE = 'code';
    const BY_PREFIX = 'prefix';
    const BY_SUBTYPE = 'subtype';
    const FALLBACK = 'fallback';

    /** Unassigned rows (fallback). */
    const ASSET_OTHER = 'asset.X';
    const LIAB_OTHER = 'liab.X';
    const EXPENSE_OTHER = 'A.5';
    const REVENUE_OTHER = 'B.2';
    const TAX_ROW = 'C.4';

    /** Account code => balance sheet row key. */
    const BALANCE_BY_CODE = [
        // assets
        '201' => 'asset.A.II.1', '203' => 'asset.A.II.1',
        '204' => 'asset.A.II.2',
        '241' => 'asset.A.II.3',
        '302' => 'asset.B.I.1', '304' => 'asset.B.I.2',
        '411' => 'asset.B.II.1',
        '501' => 'asset.B.IV.1', '503' => 'asset.B.IV.2',
        // equity
        '101' => 'liab.A.I',
        '121' => 'liab.A.III.1',
        // liabilities
        '151' => 'liab.C.I.1',
        '498' => 'liab.C.II.1',
        '401' => 'liab.C.II.2',
        '421' => 'liab.C.II.3', '454' => 'liab.C.II.3',
        '453' => 'liab.C.II.4', '455' => 'liab.C.II.4',
    ];

    /** NSS class (first two digits of the code) => balance sheet row key, for accounts not listed above. */
    const BALANCE_BY_PREFIX = [
        '10' => 'liab.A.I',        // capital
        '11' => 'liab.A.II',       // reserves
        '12' => 'liab.A.III.1',    // financial result of previous years
        '15' => 'liab.C.I.1',      // long-term loans
        '20' => 'asset.A.II.2',    // other tangible fixed assets
        '21' => 'asset.A.I',       // intangible fixed assets
        '22' => 'asset.A.III',     // long-term financial assets
        '30' => 'asset.B.I.3',     // other inventories (301 deliveries, 303 products, ...)
        '40' => 'liab.C.II.2',     // suppliers
        '41' => 'asset.B.II.1',    // customers
        '42' => 'liab.C.II.3',     // personnel
        '45' => 'liab.C.II.4',     // settlements with the budget (taxes)
        '50' => 'asset.B.IV.2',    // other cash accounts (502, 504, ...); 501 is cash in hand (by code)
    ];

    /** type / subtype => balance sheet row key. */
    const BALANCE_BY_SUBTYPE = [
        'asset' => [
            'non_current_asset' => 'asset.A.II.2',
            'current_asset' => 'asset.B.II.2',
            'receivable' => 'asset.B.II.2',
            'inventory' => 'asset.B.I.3',
            'cash' => 'asset.B.IV.2',
        ],
        'liability' => [
            'non_current_liability' => 'liab.C.I.2',
            'current_liability' => 'liab.C.II.5',
        ],
    ];

    /** Account code => income statement row key. */
    const INCOME_BY_CODE = [
        '601' => 'A.1.a', '602' => 'A.1.b',
        '604' => 'A.2.a', '605' => 'A.2.b',
        '603' => 'A.3',
        '609' => 'A.5',
        '621' => 'A.6',
        '704' => 'B.1.a', '702' => 'B.1.b', '703' => 'B.1.c',
        '709' => 'B.2',
        '721' => 'B.3',
    ];

    /** Expense subtype => income statement row key. */
    const EXPENSE_BY_SUBTYPE = [
        'cogs' => 'A.1.a', 'opex' => 'A.1.b', 'payroll' => 'A.2.a', 'depreciation' => 'A.3',
        'other' => 'A.5', 'interest' => 'A.6', 'tax' => self::TAX_ROW,
    ];

    /** Revenue subtype => income statement row key. */
    const REVENUE_BY_SUBTYPE = ['other' => 'B.2', 'interest' => 'B.3'];

    /** NSS class of a revenue / expense synthetic account => income statement row key (after the code tables). */
    const INCOME_BY_PREFIX = [
        'expense' => ['60' => 'A.5', '62' => 'A.6'],
        'revenue' => ['70' => 'B.1.d', '71' => 'B.2', '72' => 'B.3', '74' => 'B.2', '75' => 'B.2'],
    ];

    /** Synthetic accounts with their own row that are not in INCOME_BY_CODE (701 production, 606 taxes and fees). */
    const INCOME_BY_SYNTHETIC = ['701' => 'B.1.a', '606' => 'A.5'];

    /**
     * Rows of the balance sheet in print order.
     * key => [code, level, type, op, optional]
     *   level: 0 heading, 1 section (A, B, C), 2 group (I, II), 3 line (1, 2)
     *   type:  heading | subtotal | line | total | memo
     *   op:    null (a row that receives accounts) or ['sum', [keys]]
     *   optional: the row is hidden when it is zero in both columns
     */
    const BALANCE_ROWS = [
        'assets' => ['', 0, 'heading', null, false],
        'asset.U' => ['A', 1, 'line', null, false],
        'asset.A' => ['B', 1, 'subtotal', ['sum', ['asset.A.I', 'asset.A.II', 'asset.A.III', 'asset.A.IV']], false],
        'asset.A.I' => ['B.I', 2, 'line', null, false],
        'asset.A.II' => ['B.II', 2, 'subtotal', ['sum', ['asset.A.II.1', 'asset.A.II.2', 'asset.A.II.3']], false],
        'asset.A.II.1' => ['B.II.1', 3, 'line', null, false],
        'asset.A.II.2' => ['B.II.2', 3, 'line', null, false],
        'asset.A.II.3' => ['B.II.3', 3, 'line', null, false],
        'asset.A.III' => ['B.III', 2, 'line', null, false],
        'asset.A.IV' => ['B.IV', 2, 'line', null, false],
        'asset.B' => ['C', 1, 'subtotal', ['sum', ['asset.B.I', 'asset.B.II', 'asset.B.III', 'asset.B.IV']], false],
        'asset.B.I' => ['C.I', 2, 'subtotal', ['sum', ['asset.B.I.1', 'asset.B.I.2', 'asset.B.I.3']], false],
        'asset.B.I.1' => ['C.I.1', 3, 'line', null, false],
        'asset.B.I.2' => ['C.I.2', 3, 'line', null, false],
        'asset.B.I.3' => ['C.I.3', 3, 'line', null, true],
        'asset.B.II' => ['C.II', 2, 'subtotal', ['sum', ['asset.B.II.1', 'asset.B.II.2']], false],
        'asset.B.II.1' => ['C.II.1', 3, 'line', null, false],
        'asset.B.II.2' => ['C.II.2', 3, 'line', null, true],
        'asset.B.III' => ['C.III', 2, 'line', null, false],
        'asset.B.IV' => ['C.IV', 2, 'subtotal', ['sum', ['asset.B.IV.1', 'asset.B.IV.2']], false],
        'asset.B.IV.1' => ['C.IV.1', 3, 'line', null, false],
        'asset.B.IV.2' => ['C.IV.2', 3, 'line', null, false],
        'asset.C' => ['D', 1, 'line', null, false],
        'asset.X' => ['', 1, 'line', null, true],
        'asset.total' => ['', 1, 'total', ['sum', ['asset.U', 'asset.A', 'asset.B', 'asset.C', 'asset.X']], false],

        'liabilities' => ['', 0, 'heading', null, false],
        'liab.A' => ['A', 1, 'subtotal', ['sum', ['liab.A.I', 'liab.A.P', 'liab.A.R', 'liab.A.II', 'liab.A.III.1', 'liab.A.III.2']], false],
        'liab.A.I' => ['A.I', 2, 'line', null, false],
        'liab.A.P' => ['A.II', 2, 'line', null, false],
        'liab.A.R' => ['A.III', 2, 'line', null, false],
        'liab.A.II' => ['A.IV', 2, 'line', null, false],
        'liab.A.III.1' => ['A.V', 2, 'line', null, false],
        'liab.A.III.2' => ['A.VI', 2, 'line', null, false],
        'liab.A.nci' => ['', 3, 'memo', null, true],
        'liab.B' => ['B', 1, 'line', null, false],
        'liab.C' => ['C', 1, 'subtotal', ['sum', ['liab.C.I', 'liab.C.II']], false],
        'liab.C.I' => ['C.I', 2, 'subtotal', ['sum', ['liab.C.I.1', 'liab.C.I.2']], false],
        'liab.C.I.1' => ['C.I.1', 3, 'line', null, false],
        'liab.C.I.2' => ['C.I.2', 3, 'line', null, true],
        'liab.C.II' => ['C.II', 2, 'subtotal', ['sum', ['liab.C.II.1', 'liab.C.II.2', 'liab.C.II.3', 'liab.C.II.4', 'liab.C.II.5']], false],
        'liab.C.II.1' => ['C.II.1', 3, 'line', null, false],
        'liab.C.II.2' => ['C.II.2', 3, 'line', null, false],
        'liab.C.II.3' => ['C.II.3', 3, 'line', null, false],
        'liab.C.II.4' => ['C.II.4', 3, 'line', null, false],
        'liab.C.II.5' => ['C.II.5', 3, 'line', null, true],
        'liab.D' => ['D', 1, 'line', null, false],
        'liab.X' => ['', 1, 'line', null, true],
        'liab.total' => ['', 1, 'total', ['sum', ['liab.A', 'liab.B', 'liab.C', 'liab.D', 'liab.X']], false],
    ];

    /**
     * Rows of the income statement in print order (same structure as BALANCE_ROWS; op may also be
     * ['diff', minuend key, subtrahend key]; type "result" is a bold result row).
     */
    const INCOME_ROWS = [
        'exp' => ['A', 0, 'heading', null, false],
        'A.1' => ['A.1', 2, 'subtotal', ['sum', ['A.1.a', 'A.1.b']], false],
        'A.1.a' => ['A.1.a', 3, 'line', null, false],
        'A.1.b' => ['A.1.b', 3, 'line', null, false],
        'A.2' => ['A.2', 2, 'subtotal', ['sum', ['A.2.a', 'A.2.b']], false],
        'A.2.a' => ['A.2.a', 3, 'line', null, false],
        'A.2.b' => ['A.2.b', 3, 'line', null, false],
        'A.3' => ['A.3', 2, 'line', null, false],
        'A.4' => ['A.4', 2, 'line', null, false],
        'A.5' => ['A.5', 2, 'line', null, false],
        'A.I' => ['A.I', 1, 'subtotal', ['sum', ['A.1', 'A.2', 'A.3', 'A.4', 'A.5']], false],
        'A.6' => ['A.6', 2, 'line', null, false],
        'A.II' => ['A.II', 1, 'total', ['sum', ['A.I', 'A.6']], false],

        'rev' => ['B', 0, 'heading', null, false],
        'B.1' => ['B.1', 2, 'subtotal', ['sum', ['B.1.a', 'B.1.b', 'B.1.c', 'B.1.d']], false],
        'B.1.a' => ['B.1.a', 3, 'line', null, false],
        'B.1.b' => ['B.1.b', 3, 'line', null, false],
        'B.1.c' => ['B.1.c', 3, 'line', null, false],
        'B.1.d' => ['B.1.d', 3, 'line', null, true],
        'B.2' => ['B.2', 2, 'line', null, false],
        'B.I' => ['B.I', 1, 'subtotal', ['sum', ['B.1', 'B.2']], false],
        'B.3' => ['B.3', 2, 'line', null, false],
        'B.II' => ['B.II', 1, 'total', ['sum', ['B.I', 'B.3']], false],

        'res' => ['C', 0, 'heading', null, false],
        'C.1' => ['C.1', 2, 'line', ['diff', 'B.I', 'A.I'], false],
        'C.2' => ['C.2', 2, 'line', ['diff', 'B.3', 'A.6'], false],
        'C.3' => ['C.3', 1, 'result', ['diff', 'B.II', 'A.II'], false],
        'C.4' => ['C.4', 2, 'line', null, false],
        'C.5' => ['C.5', 1, 'result', ['diff', 'C.3', 'C.4'], false],
    ];

    /** NSS class prefix of the revenue accounts that are sales (70x) and are not listed in INCOME_BY_CODE. */
    const SALES_PREFIX = '70';

    /**
     * Balance sheet row of an account.
     *
     * @param array $account [code, type (asset|liability|equity), subtype]
     * @return array{0: string, 1: string} [row key, BY_CODE|BY_PREFIX|BY_SUBTYPE|FALLBACK]
     */
    public static function balanceRow(array $account): array
    {
        $code = (string)$account['code'];
        $type = (string)$account['type'];

        if (isset(self::BALANCE_BY_CODE[$code])) {
            return [self::BALANCE_BY_CODE[$code], self::BY_CODE];
        }

        $synthetic = self::synthetic($account);

        if ($synthetic !== '' && isset(self::BALANCE_BY_CODE[$synthetic]) && self::sideMatches(self::BALANCE_BY_CODE[$synthetic], $type)) {
            return [self::BALANCE_BY_CODE[$synthetic], self::BY_PREFIX];
        }

        $prefix = substr($synthetic, 0, 2);

        if (isset(self::BALANCE_BY_PREFIX[$prefix]) && self::sideMatches(self::BALANCE_BY_PREFIX[$prefix], $type)) {
            return [self::BALANCE_BY_PREFIX[$prefix], self::BY_PREFIX];
        }

        $bySubtype = self::BALANCE_BY_SUBTYPE[$type][(string)($account['subtype'] ?? '')] ?? null;

        if ($bySubtype !== null) {
            return [$bySubtype, self::BY_SUBTYPE];
        }

        return [$type === 'asset' ? self::ASSET_OTHER : self::LIAB_OTHER, self::FALLBACK];
    }

    /**
     * Income statement row of a revenue / expense account (same return value as balanceRow()).
     */
    public static function incomeRow(array $account): array
    {
        $code = (string)$account['code'];
        $type = (string)$account['type'];
        $subtype = (string)($account['subtype'] ?? '');

        if (isset(self::INCOME_BY_CODE[$code])) {
            return [self::INCOME_BY_CODE[$code], self::BY_CODE];
        }

        // an explicit "profit tax" subtype wins over the class of the code
        if ($type === 'expense' && $subtype === 'tax') {
            return [self::TAX_ROW, self::BY_SUBTYPE];
        }

        $synthetic = self::synthetic($account);
        $byTable = self::INCOME_BY_CODE[$synthetic] ?? self::INCOME_BY_SYNTHETIC[$synthetic] ?? null;

        // the row of the synthetic account must be on the same side (an expense account never lands on an income row)
        if ($synthetic !== '' && $byTable !== null && str_starts_with($byTable, $type === 'expense' ? 'A' : 'B')) {
            return [$byTable, self::BY_PREFIX];
        }

        $class = substr($synthetic, 0, 2);

        if ($type === 'expense') {
            if ($class === '62') {
                return [self::INCOME_BY_PREFIX['expense']['62'], self::BY_PREFIX];
            }

            if (isset(self::EXPENSE_BY_SUBTYPE[$subtype])) {
                return [self::EXPENSE_BY_SUBTYPE[$subtype], self::BY_SUBTYPE];
            }

            if (isset(self::INCOME_BY_PREFIX['expense'][$class])) {
                return [self::INCOME_BY_PREFIX['expense'][$class], self::BY_PREFIX];
            }

            return [self::EXPENSE_OTHER, self::FALLBACK];
        }

        if ($synthetic === '709') {
            return ['B.2', self::BY_PREFIX];
        }

        if (isset(self::INCOME_BY_PREFIX['revenue'][$class])) {
            return [self::INCOME_BY_PREFIX['revenue'][$class], self::BY_PREFIX];
        }

        if (isset(self::REVENUE_BY_SUBTYPE[$subtype])) {
            return [self::REVENUE_BY_SUBTYPE[$subtype], self::BY_SUBTYPE];
        }

        return [self::REVENUE_OTHER, self::FALLBACK];
    }

    /**
     * Synthetic account (3 digits) of an account array: the "synthetic" key when the caller resolved it from the
     * parent accounts, otherwise the first three digits of the code; '' when there are none.
     */
    public static function synthetic(array $account): string
    {
        if (isset($account['synthetic']) && $account['synthetic'] !== '') {
            return (string)$account['synthetic'];
        }

        return preg_match('/^(\d{3})/', (string)$account['code'], $m) ? $m[1] : '';
    }

    /** Lang key of a row: asset.A.II.1 => asset_A_II_1. */
    public static function langKey(string $rowKey): string
    {
        return str_replace('.', '_', $rowKey);
    }

    /**
     * The printed code of a row: the Latin placeholders A..D (and a..d) become the letters of the NSS form
     * (lang "letters"), roman numerals and arabic numbers stay as they are.
     */
    public static function displayCode(string $code): string
    {
        if ($code === '') {
            return '';
        }

        $letters = (array)__('databackd-gaps-statutory.letters');

        return implode('.', array_map(function ($part) use ($letters) {
            return $letters[$part] ?? $part;
        }, explode('.', $code)));
    }

    /** Whether the row key belongs to the side (assets / liabilities) of the account type. */
    protected static function sideMatches(string $rowKey, string $type): bool
    {
        return $type === 'asset' ? str_starts_with($rowKey, 'asset.') : str_starts_with($rowKey, 'liab.');
    }

}
