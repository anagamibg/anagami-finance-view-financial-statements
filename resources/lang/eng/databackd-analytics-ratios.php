<?php

return [
    'menu' => 'Financial Ratios',
    'title' => 'Financial Ratios',
    'subtitle' => 'Key liquidity, profitability, leverage and efficiency indicators calculated from the accounting entries.',

    'filters' => [
        'period_type' => 'Period type',
        'period' => 'Period',
        'entity' => 'Legal entity',
        'all_entities' => 'All legal entities',
        'consolidated_group' => 'Consolidated',
    ],

    'months' => [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ],
    'quarter_label' => ':q quarter :year',
    'quarter_short' => ':q qtr :year',
    'year_label' => 'Year :year',

    'period_caption' => 'Period: :from – :to',
    'compared_with' => 'Compared with: :period',
    'entity_caption' => 'Data for: :entity',

    'summary' => [
        'good' => 'Within or better than target',
        'warning' => 'Close to target',
        'bad' => 'Outside target',
        'total' => 'Total indicators',
    ],

    'target' => 'Target',
    'target_range' => ':min – :max',
    'target_min' => 'min. :min',
    'target_max' => 'max. :max',
    'no_target' => 'No target set',
    'formula' => 'Formula',
    'previous' => 'Previous period',
    'change' => 'Change versus the previous period',
    'change_pp' => 'pp',
    'change_days' => 'days',
    'no_change' => 'No comparison',
    'sparkline_title' => 'Last 12 Months',
    'higher_better' => 'A higher value is better',
    'lower_better' => 'A lower value is better',
    'system_ratio' => 'System',
    'custom_ratio' => 'Custom',
    'open_detail' => 'Details',
    'ratios_count' => ':count indicators',

    'actions' => [
        'export' => 'Export to Excel',
        'comparison' => 'Comparison',
        'builder' => 'Formula builder',
        'reset' => 'Clear filters',
    ],

    'empty' => [
        'title' => 'No ratios defined yet',
        'text' => 'System ratios are not available for this company. Add your own ratios or try again later.',
        'group' => 'No indicators in this group.',
    ],

    'installed_notice' => 'The system financial ratios were added automatically.',
    'no_data_notice' => 'There are no accounting entries for the selected period, so the values cannot be calculated.',

    'how_to_read' => 'How to read it',
    'click_for_detail' => 'Click for the chart, the formula and the values by legal entity.',
    'not_calculated' => 'Cannot be calculated for the period - data is missing or the denominator is 0. Open the detail to see why.',
    'help' => [
        'title' => 'How to Read the Ratios',
        'intro' => 'Every ratio is calculated from the accounting entries of the selected period and compared with its target range. Under the value a short sentence explains what it means, and the ⓘ icon shows the description of the ratio.',
        'good' => 'Green - the value is within the target range or better.',
        'warning' => 'Amber - the value is slightly outside the target (up to 15 % from the bound).',
        'bad' => 'Red - the value is outside the target in the unfavourable direction.',
        'delta' => 'The arrow shows the change versus the previous period: green for an improvement, red for a deterioration.',
        'gauge' => 'In the bar under the status the light green zone is the target range and the coloured line is the current value.',
        'detail' => 'Click a card for an 18-month chart, the formula and the values by legal entity.',
        'targets' => 'You can change the targets in the ratio builder.',
    ],

    // System ratios: name, description and a plain-language "how to read" sentence (:value - the formatted value,
    // :number - the number without the unit, :percent - a coefficient shown as percent)
    'system' => [
        'current_ratio' => [
            'name' => 'Current Ratio',
            'description' => 'Current assets / current liabilities. Shows whether the company can cover the liabilities due within a year with its cash, receivables and inventory. Below 1 means a shortage of working capital; a very high value means idle funds.',
            'read' => 'For every 1 € of liabilities due within a year the company has :value € in cash, receivables and inventory.',
        ],
        'quick_ratio' => [
            'name' => 'Quick Ratio',
            'description' => '(Cash + receivables) / current liabilities. Stricter than the current ratio because it leaves out inventory, which turns into cash more slowly.',
            'read' => 'Without selling any inventory the company has :value € in cash and receivables for every 1 € of current liabilities.',
        ],
        'cash_ratio' => [
            'name' => 'Cash Ratio',
            'description' => 'Cash / current liabilities. Shows what part of the current liabilities can be paid at once, only with the cash on hand and in bank accounts.',
            'read' => 'For every 1 € of current liabilities the company has :value € of cash on hand and in bank accounts.',
        ],
        'net_margin' => [
            'name' => 'Net Profit Margin',
            'description' => 'Net profit / revenue, in percent. How much of the revenue is left as profit after all expenses.',
            'read' => 'Out of every 100 € of revenue :number € is left as profit after all expenses.',
        ],
        'gross_margin' => [
            'name' => 'Gross Margin',
            'description' => '(Operating revenue - cost of sales) / operating revenue, in percent. The cost of sales is the cost of materials (subtype "Cost of sales"). Shows what is left for salaries, rent and the other expenses.',
            'read' => 'Out of every 100 € of sales :number € is left after the cost of materials - salaries, rent and the other expenses are paid from it.',
        ],
        'ebitda_margin' => [
            'name' => 'EBITDA Margin',
            'description' => 'EBITDA / operating revenue, in percent. EBITDA = profit + depreciation + interest expense - interest income (the chart of accounts has no tax expense). Shows the profitability of the core business before financing and depreciation.',
            'read' => 'Out of every 100 € of sales the core business earns :number € before depreciation and interest.',
        ],
        'roa' => [
            'name' => 'Return on Assets (ROA)',
            'description' => 'Net profit / average assets, in percent per year. Shows how well the company uses its property to earn a profit.',
            'read' => 'Every 100 € of assets (buildings, machines, cash, receivables, inventory) earns :number € of profit a year.',
        ],
        'roe' => [
            'name' => 'Return on Equity (ROE)',
            'description' => 'Net profit / average equity, in percent per year. Shows the return the owners get on the capital they invested. Not calculated when the equity is negative.',
            'read' => 'Every 100 € invested by the owners earns :number € of profit a year.',
        ],
        'debt_ratio' => [
            'name' => 'Debt Ratio',
            'description' => 'Total liabilities / total assets. The part of the assets financed with loans and other liabilities. Above 0.7 the financial risk is high; a very low value can mean unused financing options.',
            'read' => ':percent of the company\'s property is financed with loans and other liabilities.',
        ],
        'debt_to_equity' => [
            'name' => 'Debt to Equity',
            'description' => 'Total liabilities / equity. How many euros of borrowed funds there are for every euro of equity. A lower value means more financial independence. Not calculated when the equity is negative.',
            'read' => 'For every 1 € of equity the company has :value € of liabilities.',
        ],
        'receivable_days' => [
            'name' => 'Receivable Days',
            'description' => 'Average receivables / operating revenue × days in the period. The average number of days customers take to pay. Less is better; above 45 days the risk of overdue payments grows.',
            'read' => 'Customers pay on average :value after the sale.',
        ],
        'payable_days' => [
            'name' => 'Payable Days',
            'description' => 'Average payables to suppliers / (cost of sales + operating expenses) × days in the period. The average number of days the company takes to pay its suppliers. Above 60 days means a risk of late payments.',
            'read' => 'The company pays its suppliers on average :value after the purchase.',
        ],
        'inventory_days' => [
            'name' => 'Inventory Days',
            'description' => 'Average inventory / cost of sales × days in the period. How many days the inventory stays in stock before it is used or sold. Less is better because less money is tied up in stock.',
            'read' => 'Goods and materials stay in stock on average :value before they are used or sold.',
        ],
        'asset_turnover' => [
            'name' => 'Asset Turnover',
            'description' => 'Operating revenue / average assets, per year. How many euros of sales every euro of assets brings. A higher value means the property is used more efficiently.',
            'read' => 'Every 1 € of assets brings :value € of sales a year.',
        ],
        'payroll_to_revenue' => [
            'name' => 'Payroll to Revenue',
            'description' => 'Salaries and social security / operating revenue, in percent. How much of the revenue goes to staff. Above 40 % weighs on the profit, especially with low margins.',
            'read' => 'Out of every 100 € of sales :number € goes to salaries and social security.',
        ],
    ],
];
