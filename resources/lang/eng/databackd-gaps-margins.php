<?php

return [
    'menu' => 'Product Margins',
    'title' => 'Margins by Products and Services',
    'subtitle' => 'Revenue, cost and gross margin by product and product group, from the issued invoices.',
    'currency_note' => 'Amounts are net of VAT, in :currency.',

    // the "Cost price" field in the products module
    'cost_price' => [
        'label' => 'Cost price',
        'placeholder' => 'e.g. 45.00',
        'help' => 'Cost of one unit. Used in the report "Margins by products and services"; leave empty if unknown.',
    ],

    'filters' => [
        'period' => 'Period',
        'from' => 'From',
        'to' => 'To',
        'category' => 'Product group',
        'client' => 'Client',
        'compare' => 'Compare with',
        'all' => 'All',
        'apply' => 'Apply',
        'reset' => 'Reset',
    ],
    'periods' => [
        'today' => 'Today',
        'this_week' => 'This week',
        'this_month' => 'This month',
        'last_3_months' => 'Last 3 months',
        'last_6_months' => 'Last 6 months',
        'last_12_months' => 'Last 12 months',
        'this_year' => 'This year',
        'last_year' => 'Last year',
    ],
    'compare' => [
        'none' => 'No comparison',
        'previous' => 'Previous period',
        'last_year' => 'Same period last year',
    ],
    'compare_period' => 'Comparison period: :from – :to',
    'vs_previous' => 'vs. comparison period',
    'pp' => 'p.p.',

    'actions' => [
        'excel' => 'Excel',
        'pdf' => 'PDF',
        'print' => 'Print',
    ],

    'kpi' => [
        'revenue' => 'Revenue',
        'cost' => 'Cost',
        'margin' => 'Gross margin',
        'margin_pct' => 'Margin %',
        'revenue_hint' => ':count invoices, net of credit notes',
        'cost_hint' => 'Quantity × cost price',
        'margin_hint' => 'Revenue − cost, for products with a cost price',
        'coverage' => ':pct% of the revenue has a cost price',
        'no_cost' => 'No cost prices set',
    ],

    'alerts' => [
        'missing_title' => 'Cost price is missing for :count of :total products',
        'missing_text' => 'These products account for :pct% of the revenue (:amount). The margin is calculated only for the products that have a cost price; set the cost price of the others to see the full picture.',
        'missing_all' => 'No product has a cost price yet. Set the cost price in the product card (field "Cost price") to see the cost and the margin.',
        'set_cost' => 'Set cost price',
        'more' => 'and :count more (see the table)',
        'group_unmatched' => 'The selected product group has no matching product category, so there is nothing to show.',
        'entity_ignored' => 'The legal entity filter does not apply to this report: invoices are not linked to legal entities.',
    ],

    'charts' => [
        'products' => 'Gross margin by product',
        'products_sub' => 'Top 10 by margin amount, products with a cost price only',
        'groups_share' => 'Revenue by product group',
        'groups_compare' => 'Revenue, cost and gross margin by product group',
        'revenue' => 'Revenue',
        'cost' => 'Cost',
        'margin' => 'Gross margin',
        'margin_pct' => 'Margin',
        'no_margin' => 'No product with a cost price in the selected period',
    ],

    'groups' => [
        'title' => 'By Product Group',
        'products' => 'Products',
        'count' => ':count products',
    ],

    'table' => [
        'title' => 'By Product and Service',
        'search' => 'Search by product or group...',
        'total' => 'Total',
        'unlinked' => 'Invoice lines without a product',
        'no_cost' => 'No cost price',
        'no_cost_hint' => 'The cost price of this product is not set, so the cost and the margin cannot be calculated.',
        'columns' => [
            'product' => 'Product / Service',
            'group' => 'Group',
            'quantity' => 'Quantity',
            'revenue' => 'Revenue',
            'cost' => 'Cost',
            'margin' => 'Gross Margin',
            'margin_pct' => 'Margin %',
            'share' => 'Share of Revenue',
            'prev_margin' => 'Margin, comparison period',
            'change' => 'Change',
            'sku' => 'SKU',
            'cost_price' => 'Unit Cost Price',
            'avg_price' => 'Average Unit Price',
            'credited' => 'Credit Notes',
            'prev_revenue' => 'Revenue, comparison period',
        ],
        'credited' => 'of which credit notes: :amount',
    ],

    'unlinked' => 'Lines without a product',
    'no_group' => 'No group',

    'notes' => [
        'title' => 'How the Report is Calculated',
        'revenue' => 'Revenue is the net amount (without VAT) of the invoice lines of the invoices issued in the period (paid, unpaid and partially paid; drafts and cancelled invoices are excluded), minus the credit notes issued in the period.',
        'cost' => 'Cost = invoiced quantity × the current cost price of the product (field "Cost price" in the product card). Products without a cost price have no cost and no margin and are left out of the margin totals.',
        'group' => 'Product group = the category of the product.',
        'data_as_of' => 'Data as of',
    ],

    'empty' => [
        'title' => 'No sales in the selected period',
        'text' => 'There are no issued invoices with products for the period and filters. Try another period.',
    ],

    'export' => [
        'file' => 'margins-by-products',
        'sheet_products' => 'Products',
        'sheet_groups' => 'Product groups',
        'pdf_title' => 'Margins by Products and Services',
        'page' => 'Page :n of :total',
    ],
];
