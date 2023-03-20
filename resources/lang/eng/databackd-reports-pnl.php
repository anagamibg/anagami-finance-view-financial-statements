<?php

return [
    'menu' => 'P&L (Income Statement)',
    'title' => 'Profit and Loss Statement',
    'subtitle' => 'Income statement by the Bulgarian accounting standards: net sales, operating costs, financial income and expenses, profit before tax and net profit. Click a row to expand it by accounts.',

    'sections' => [
        'revenue' => 'Revenue',
        'expense' => 'Expenses',
        'financial' => 'Financial activity',
        'result' => 'Financial result',
    ],

    'rows' => [
        'net_sales' => 'Net sales revenue',
        'other_income' => 'Other income',
        'operating_revenue' => 'Total operating revenue',
        'materials' => 'Cost of materials',
        'services' => 'External services',
        'depreciation' => 'Depreciation and amortization',
        'payroll' => 'Payroll',
        'social' => 'Social security contributions',
        'other_expense' => 'Other expenses',
        'operating_expense' => 'Total operating expenses',
        'operating_profit' => 'Operating profit',
        'fin_income' => 'Financial income',
        'fin_expense' => 'Financial expenses',
        'profit_before_tax' => 'Profit before tax',
        'tax_estimated' => 'Corporate tax 10% (estimated)',
        'tax_actual' => 'Profit tax',
        'net_profit' => 'Net profit',
    ],

    'columns' => [
        'item' => 'Item',
        'code' => 'Account',
        'total' => 'Total',
        'pct' => 'Share of Revenue (%)',
        'previous' => 'Previous Period',
        'change_abs' => 'Change',
        'change' => 'Change %',
    ],

    'kpi' => [
        'net_sales' => 'Net revenue',
        'operating_profit' => 'Operating profit',
        'net_profit' => 'Net profit',
        'margin' => 'Net margin',
        'vs_previous' => 'vs previous period',
        'pp' => 'pp',
    ],

    'actions' => [
        'excel' => 'Export to Excel',
        'pdf' => 'Export to PDF',
        'print' => 'Print',
        'expand_all' => 'Expand all',
        'collapse_all' => 'Collapse all',
        'toggle' => 'Expand / collapse by accounts',
        'open_entries' => 'Show the entries of the account',
    ],

    'notes' => [
        'title' => 'Notes',
        'pct_base' => 'The "Share of Revenue (%)" column is relative to net sales revenue (accounts 70x except 709) for the whole period.',
        'tax_estimated' => 'The chart of accounts has no profit tax account, so the tax is an estimate: :rate% of the positive profit before tax for the period, spread over the columns in proportion to the profit.',
        'tax_actual' => 'The profit tax is taken from the entries of accounts with the "tax" subtype.',
        'other_accounts' => 'Accounts without a group in the national chart of accounts and without a subtype (:codes) are included in "Other income" / "Other expenses". Analytic accounts (e.g. 7031 or a sub-account of 703) follow the group of their synthetic account.',
        'expenses_positive' => 'Expenses are shown as positive amounts; profits are revenue minus expenses.',
        'dimension_revenue_only' => 'Filtering by region, segment, product group or client only matches revenue (expenses have none of these dimensions), so expenses are not included and the result equals revenue.',
        'dimension_expense_only' => 'Filtering by cost center only matches expenses (revenue has no cost center), so revenue is not included.',
        'dimension_none' => 'Combining a revenue dimension filter with a cost center filter matches no entries - revenue and expenses share no dimension.',
    ],

    'compare_period' => 'Compared with: :from – :to',
    'print_header' => 'Profit and loss statement',
    'generated_at' => 'Generated on :date',
    'empty' => 'No data for the selected period and filters.',
    'currency_note' => 'All amounts are in :currency.',

    'export' => [
        'sheet' => 'P&L',
        'file' => 'pnl',
        'pdf_title' => 'Profit and Loss Statement',
        'page' => 'Block :n of :total',
    ],
];
