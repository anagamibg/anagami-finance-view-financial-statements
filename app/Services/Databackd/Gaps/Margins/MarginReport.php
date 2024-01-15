<?php

namespace App\Services\Databackd\Gaps\Margins;

use App\Models\Databackd\FinDimension;
use App\Services\Databackd\Reports\ReportFilters;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Margins by products and services (B2, S8): revenue, cost and gross margin per product and per product group.
 *
 * Source of the numbers (NOT the ledger, which has no products): the invoices.
 *   - revenue  = net amount of the invoice lines (invoice_items.amount, after the invoice level discount) of the invoices
 *                issued in the period with status paid / unpaid / partial (drafts, cancelled and pending invoices are left out),
 *                minus the net amount of the credit notes issued in the period (credit_note_items, by product);
 *   - quantity = invoiced quantity (credit notes reduce the revenue only - a credit note can be a price correction as well
 *                as a return, so the quantity and the cost are not reduced);
 *   - cost     = quantity x products.cost_price (the current cost price; there is no cost history). NULL cost price = unknown:
 *                such a product has no cost, no margin and is counted in "missing" (never treated as zero cost);
 *   - margin   = revenue - cost; margin % = margin / revenue. The totals compute the margin only over the revenue of the
 *                products that have a cost price (totals.costed_revenue), totals.coverage_pct tells which part of the
 *                revenue that is.
 *   - product group = product category (products.category_id -> product_category). A fin_dimensions product group in the
 *                filters (ReportFilters::$productGroupId) is mapped to the category with the same name.
 *
 * Invoice lines without a product (or with a deleted product) go to one row (key "unlinked", product_id null) without cost.
 * Foreign currency invoices are converted with the exchange rate of the invoice (like the ledger sync does); BGN <-> EUR
 * always uses the fixed legal rate 1.95583 (BGN invoices from before the euro switch carry a rate against BGN, not EUR).
 * The legal entity filter does not apply: invoices have no legal entity (reported in notes.entity_ignored).
 *
 * Works without a session: pass the company id (defaults to the company of the logged in user).
 */
class MarginReport
{

    /** Invoice statuses that count as revenue (issued documents). */
    const STATUSES = ['paid', 'unpaid', 'partial'];

    const UNLINKED = 'unlinked';
    const NO_GROUP = 'none';

    protected int $companyId;
    protected int $currencyId = 0;
    protected string $currencyCode = 'EUR';

    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId ?? (int)company()->id;

        $currency = DB::table('companies')
            ->leftJoin('currencies', 'currencies.id', '=', 'companies.currency_id')
            ->where('companies.id', $this->companyId)
            ->first(['companies.currency_id', 'currencies.currency_code']);

        $this->currencyId = (int)($currency->currency_id ?? 0);
        $this->currencyCode = $currency->currency_code ?? 'EUR';
    }

    /**
     * Builds the report.
     *
     * @param ReportFilters|array $filters period / comparison / client from ReportFilters (array = ReportFilters::fromArray
     *                                     format, plus the optional key "category_id")
     * @param int|null $categoryId product category (product group) filter; overrides filters
     *
     * @return array{
     *   period: array{from: Carbon, to: Carbon},
     *   currency: string,
     *   rows: array<int, array<string, mixed>>,
     *   groups: array<int, array<string, mixed>>,
     *   totals: array<string, mixed>,
     *   missing: array<int, array<string, mixed>>,
     *   compare: array|null,
     *   filters: array{category_id: int|null, category: string|null, client_id: int|null},
     *   notes: array{entity_ignored: bool, group_unmatched: bool},
     *   catalog: array{products: int, without_cost: int}
     * }
     *
     * A row: key, product_id (null = unlinked), name, sku, group_id (null = no group), group, added_by, unit_price, cost_price,
     * quantity, invoices, invoiced, credited, revenue, avg_price, cost, margin, margin_pct, share (% of the total revenue),
     * has_cost, and with a comparison also prev_revenue, prev_margin, prev_margin_pct, revenue_change_pct, margin_change_pct,
     * margin_change_pp.
     * A group: id, name, products, quantity, revenue, cost, costed_revenue, margin, margin_pct, share, missing_count (+ prev_*).
     * Totals: revenue, invoiced, credited, quantity, invoices, products, cost, costed_revenue, margin, margin_pct, coverage_pct,
     * missing_count, missing_revenue.
     */
    public function build(ReportFilters|array $filters, ?int $categoryId = null): array
    {
        $extraCategory = null;

        if (is_array($filters)) {
            $extraCategory = isset($filters['category_id']) && is_numeric($filters['category_id']) && (int)$filters['category_id'] > 0
                ? (int)$filters['category_id'] : null;
            $filters = ReportFilters::fromArray($filters);
        }

        $groupUnmatched = false;
        $categoryId = $categoryId ?: $extraCategory;

        if (!$categoryId && $filters->productGroupId) {
            $categoryId = $this->categoryOfProductGroup((int)$filters->productGroupId);

            if (!$categoryId) {
                $categoryId = -1; // a product group without a matching category: nothing to show
                $groupUnmatched = true;
            }
        }

        $categoryName = $categoryId && $categoryId > 0
            ? DB::table('product_category')->where('company_id', $this->companyId)->where('id', $categoryId)->value('category_name')
            : null;

        $clientId = $filters->clientId ?: null;
        $current = $this->compute($filters->from, $filters->to, $categoryId, $clientId);

        $compare = null;
        $comparePeriod = $filters->comparePeriod();

        if ($comparePeriod) {
            $previous = $this->compute($comparePeriod['from'], $comparePeriod['to'], $categoryId, $clientId);
            $current = $this->mergeComparison($current, $previous);
            $compare = [
                'from' => $comparePeriod['from'],
                'to' => $comparePeriod['to'],
                'type' => $filters->compare,
                'totals' => $previous['totals'],
                'changes' => $this->totalChanges($current['totals'], $previous['totals']),
            ];
        }

        $missing = array_values(array_filter($current['rows'], fn($r) => !$r['has_cost']));
        usort($missing, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'period' => ['from' => $filters->from->copy(), 'to' => $filters->to->copy()],
            'currency' => $this->currencyCode,
            'rows' => $current['rows'],
            'groups' => $current['groups'],
            'totals' => $current['totals'],
            'missing' => $missing,
            'compare' => $compare,
            'filters' => [
                'category_id' => $categoryId && $categoryId > 0 ? $categoryId : null,
                'category' => $categoryName,
                'client_id' => $clientId,
            ],
            'notes' => [
                'entity_ignored' => !empty($filters->entityIds),
                'group_unmatched' => $groupUnmatched,
            ],
            'catalog' => $this->catalog(),
        ];
    }

    /**
     * Data for the charts of the screen: margin by product (top N by margin amount) and revenue / cost / margin by group.
     *
     * @param array $report result of build()
     *
     * @return array{products: array, groups: array}
     */
    public static function chartData(array $report, int $topProducts = 10): array
    {
        $products = array_values(array_filter($report['rows'], fn($r) => $r['has_cost']));
        usort($products, fn($a, $b) => $b['margin'] <=> $a['margin']);
        $products = array_slice($products, 0, $topProducts);

        $groups = array_values(array_filter($report['groups'], fn($g) => abs($g['revenue']) > 0.004));

        return [
            'products' => [
                'labels' => array_column($products, 'name'),
                'margin' => array_column($products, 'margin'),
                'margin_pct' => array_column($products, 'margin_pct'),
                'revenue' => array_column($products, 'revenue'),
            ],
            'groups' => [
                'labels' => array_column($groups, 'name'),
                'revenue' => array_column($groups, 'revenue'),
                'cost' => array_map(fn($g) => $g['cost'] ?? 0, $groups),
                'margin' => array_map(fn($g) => $g['margin'] ?? 0, $groups),
                'margin_pct' => array_column($groups, 'margin_pct'),
                'has_margin' => array_map(fn($g) => $g['margin'] !== null, $groups),
            ],
        ];
    }

    /**
     * Product categories of the company (the "product groups" of this report): id => name.
     *
     * @return array<int, string>
     */
    public function categories(): array
    {
        return DB::table('product_category')->where('company_id', $this->companyId)->orderBy('category_name')->pluck('category_name', 'id')->all();
    }

    /**
     * Clients that have invoices (for the client filter): id => name.
     *
     * @return array<int, string>
     */
    public function clients(): array
    {
        return DB::table('invoices as i')
            ->join('users as u', 'u.id', '=', 'i.client_id')
            ->where('i.company_id', $this->companyId)
            ->whereIn('i.status', self::STATUSES)
            ->select('u.id', 'u.name')->distinct()
            ->orderBy('u.name')
            ->pluck('u.name', 'u.id')
            ->all();
    }

    // -------------------------------------------------------------------------------------------------------------

    /**
     * @return array{rows: array, groups: array, totals: array}
     */
    protected function compute(Carbon $from, Carbon $to, ?int $categoryId, ?int $clientId): array
    {
        $sold = $this->soldLines($from, $to, $categoryId, $clientId);
        $credited = $this->creditedLines($from, $to, $categoryId, $clientId);
        $invoiceCount = $this->invoiceCount($from, $to, $categoryId, $clientId);

        $ids = array_values(array_filter(array_unique(array_merge(array_keys($sold), array_keys($credited))), fn($id) => $id > 0));
        $products = $ids
            ? DB::table('products')->where('company_id', $this->companyId)->whereIn('id', $ids)
                ->get(['id', 'name', 'sku', 'category_id', 'price', 'cost_price', 'added_by'])->keyBy('id')
            : collect();
        $categories = $this->categories();

        $buckets = [];

        foreach ([$sold, $credited] as $source) {
            foreach ($source as $productId => $line) {
                // a missing / deleted product behaves like an invoice line without a product
                $key = $productId > 0 && $products->has($productId) ? (int)$productId : self::UNLINKED;
                $b = &$buckets[$key];
                $b ??= ['quantity' => 0.0, 'invoiced' => 0.0, 'credited' => 0.0, 'invoices' => 0];
                $b['quantity'] += $line['quantity'] ?? 0;
                $b['invoiced'] += $line['invoiced'] ?? 0;
                $b['credited'] += $line['credited'] ?? 0;
                $b['invoices'] += $line['invoices'] ?? 0;
                unset($b);
            }
        }

        $rows = [];

        foreach ($buckets as $key => $b) {
            $p = $key === self::UNLINKED ? null : $products->get($key);
            $costPrice = $p && $p->cost_price !== null ? (float)$p->cost_price : null;
            $revenue = round($b['invoiced'] - $b['credited'], 2);
            $quantity = round($b['quantity'], 4);
            $cost = $costPrice !== null ? round($quantity * $costPrice, 2) : null;
            $margin = $cost !== null ? round($revenue - $cost, 2) : null;
            $groupId = $p && $p->category_id && isset($categories[$p->category_id]) ? (int)$p->category_id : null;

            $rows[] = [
                'key' => $key,
                'product_id' => $p ? (int)$p->id : null,
                'name' => $p ? $p->name : __('databackd-gaps-margins.unlinked'),
                'sku' => $p->sku ?? null,
                'group_id' => $groupId,
                'group' => $groupId ? $categories[$groupId] : __('databackd-gaps-margins.no_group'),
                'added_by' => $p->added_by ?? null,
                'unit_price' => $p ? (float)$p->price : null,
                'cost_price' => $costPrice,
                'quantity' => $quantity,
                'invoices' => (int)$b['invoices'],
                'invoiced' => round($b['invoiced'], 2),
                'credited' => round($b['credited'], 2),
                'revenue' => $revenue,
                'avg_price' => $quantity > 0 ? round($b['invoiced'] / $quantity, 2) : null,
                'cost' => $cost,
                'margin' => $margin,
                'margin_pct' => $margin !== null && abs($revenue) > 0.004 ? round($margin / $revenue * 100, 2) : null,
                'has_cost' => $costPrice !== null,
                'share' => 0.0,
            ];
        }

        usort($rows, fn($a, $b) => [$b['revenue'], $a['name']] <=> [$a['revenue'], $b['name']]);

        $totals = $this->totals($rows, $invoiceCount);

        foreach ($rows as &$row) {
            $row['share'] = $totals['revenue'] > 0.004 ? round($row['revenue'] / $totals['revenue'] * 100, 2) : 0.0;
        }
        unset($row);

        return ['rows' => $rows, 'groups' => $this->groups($rows, $totals), 'totals' => $totals];
    }

    /**
     * @return array<string, mixed>
     */
    protected function totals(array $rows, int $invoiceCount): array
    {
        $t = [
            'revenue' => 0.0, 'invoiced' => 0.0, 'credited' => 0.0, 'quantity' => 0.0, 'invoices' => $invoiceCount,
            'products' => 0, 'cost' => 0.0, 'costed_revenue' => 0.0, 'costed_count' => 0, 'missing_count' => 0, 'missing_revenue' => 0.0,
        ];

        foreach ($rows as $r) {
            $t['revenue'] += $r['revenue'];
            $t['invoiced'] += $r['invoiced'];
            $t['credited'] += $r['credited'];
            $t['quantity'] += $r['quantity'];
            $t['products'] += $r['product_id'] ? 1 : 0;

            if ($r['has_cost']) {
                $t['cost'] += $r['cost'];
                $t['costed_revenue'] += $r['revenue'];
                $t['costed_count']++;
            } else {
                $t['missing_count']++;
                $t['missing_revenue'] += $r['revenue'];
            }
        }

        foreach (['revenue', 'invoiced', 'credited', 'cost', 'costed_revenue', 'missing_revenue'] as $k) {
            $t[$k] = round($t[$k], 2);
        }

        $t['quantity'] = round($t['quantity'], 4);
        $t['margin'] = $t['costed_count'] > 0 ? round($t['costed_revenue'] - $t['cost'], 2) : null;
        $t['margin_pct'] = $t['margin'] !== null && abs($t['costed_revenue']) > 0.004 ? round($t['margin'] / $t['costed_revenue'] * 100, 2) : null;
        $t['coverage_pct'] = abs($t['revenue']) > 0.004 ? round($t['costed_revenue'] / $t['revenue'] * 100, 1) : null;

        return $t;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function groups(array $rows, array $totals): array
    {
        $groups = [];

        foreach ($rows as $r) {
            $key = $r['group_id'] ?? self::NO_GROUP;
            $g = &$groups[$key];
            $g ??= [
                'id' => $r['group_id'], 'name' => $r['group'], 'products' => 0, 'quantity' => 0.0, 'revenue' => 0.0, 'cost' => 0.0,
                'costed_revenue' => 0.0, 'costed_count' => 0, 'missing_count' => 0, 'missing_revenue' => 0.0,
            ];
            $g['products'] += $r['product_id'] ? 1 : 0;
            $g['quantity'] += $r['quantity'];
            $g['revenue'] += $r['revenue'];

            if ($r['has_cost']) {
                $g['cost'] += $r['cost'];
                $g['costed_revenue'] += $r['revenue'];
                $g['costed_count']++;
            } else {
                $g['missing_count']++;
                $g['missing_revenue'] += $r['revenue'];
            }
            unset($g);
        }

        $out = [];

        foreach ($groups as $g) {
            $hasCost = $g['costed_count'] > 0;
            $g['revenue'] = round($g['revenue'], 2);
            $g['quantity'] = round($g['quantity'], 4);
            $g['cost'] = $hasCost ? round($g['cost'], 2) : null;
            $g['costed_revenue'] = round($g['costed_revenue'], 2);
            $g['missing_revenue'] = round($g['missing_revenue'], 2);
            $g['margin'] = $hasCost ? round($g['costed_revenue'] - $g['cost'], 2) : null;
            $g['margin_pct'] = $g['margin'] !== null && abs($g['costed_revenue']) > 0.004 ? round($g['margin'] / $g['costed_revenue'] * 100, 2) : null;
            $g['share'] = $totals['revenue'] > 0.004 ? round($g['revenue'] / $totals['revenue'] * 100, 2) : 0.0;
            $out[] = $g;
        }

        usort($out, fn($a, $b) => [$b['revenue'], $a['name']] <=> [$a['revenue'], $b['name']]);

        return $out;
    }

    /**
     * Adds the "prev_*" / change fields of the comparison period to the rows and the groups.
     */
    protected function mergeComparison(array $current, array $previous): array
    {
        $prevRows = [];
        foreach ($previous['rows'] as $r) {
            $prevRows[$r['key']] = $r;
        }

        $prevGroups = [];
        foreach ($previous['groups'] as $g) {
            $prevGroups[$g['id'] ?? self::NO_GROUP] = $g;
        }

        foreach ($current['rows'] as &$r) {
            $p = $prevRows[$r['key']] ?? null;
            $r = array_merge($r, $this->comparison($r, $p));
        }
        unset($r);

        foreach ($current['groups'] as &$g) {
            $p = $prevGroups[$g['id'] ?? self::NO_GROUP] ?? null;
            $g = array_merge($g, $this->comparison($g, $p));
        }
        unset($g);

        return $current;
    }

    /**
     * @param array|null $prev the same row / group in the comparison period
     */
    protected function comparison(array $cur, ?array $prev): array
    {
        $prevRevenue = $prev['revenue'] ?? 0.0;
        $prevMargin = $prev['margin'] ?? null;
        $prevPct = $prev['margin_pct'] ?? null;

        return [
            'prev_revenue' => $prevRevenue,
            'prev_margin' => $prevMargin,
            'prev_margin_pct' => $prevPct,
            'revenue_change_pct' => self::changePct($cur['revenue'], $prevRevenue),
            'margin_change_pct' => $cur['margin'] !== null && $prevMargin !== null ? self::changePct($cur['margin'], $prevMargin) : null,
            'margin_change_pp' => $cur['margin_pct'] !== null && $prevPct !== null ? round($cur['margin_pct'] - $prevPct, 1) : null,
        ];
    }

    /**
     * @return array<string, float|null>
     */
    protected function totalChanges(array $cur, array $prev): array
    {
        return [
            'revenue_pct' => self::changePct($cur['revenue'], $prev['revenue']),
            'cost_pct' => $cur['cost'] != 0.0 && $prev['cost'] != 0.0 ? self::changePct($cur['cost'], $prev['cost']) : null,
            'margin_pct' => $cur['margin'] !== null && $prev['margin'] !== null ? self::changePct($cur['margin'], $prev['margin']) : null,
            'margin_pp' => $cur['margin_pct'] !== null && $prev['margin_pct'] !== null ? round($cur['margin_pct'] - $prev['margin_pct'], 1) : null,
        ];
    }

    public static function changePct(float $current, float $previous): ?float
    {
        return abs($previous) > 0.004 ? round(($current - $previous) / abs($previous) * 100, 1) : null;
    }

    // ------------------------------------------------------------------------------------------------------ queries

    /**
     * Invoice lines of the issued invoices of the period, per product id (0 = no product).
     *
     * @return array<int, array{quantity: float, invoiced: float, invoices: int}>
     */
    protected function soldLines(Carbon $from, Carbon $to, ?int $categoryId, ?int $clientId): array
    {
        $factor = $this->discountFactor('i') . ' * ' . $this->fxFactor('i.currency_id', 'i.exchange_rate');

        return $this->invoiceQuery($from, $to, $categoryId, $clientId)
            ->groupBy('ii.product_id')
            ->selectRaw("COALESCE(ii.product_id, 0) as pid, SUM(ii.quantity) as qty, SUM(ii.amount * {$factor}) as amount, COUNT(DISTINCT i.id) as invoices")
            ->get()
            ->mapWithKeys(fn($r) => [(int)$r->pid => ['quantity' => (float)$r->qty, 'invoiced' => (float)$r->amount, 'invoices' => (int)$r->invoices]])
            ->all();
    }

    protected function invoiceCount(Carbon $from, Carbon $to, ?int $categoryId, ?int $clientId): int
    {
        return (int)$this->invoiceQuery($from, $to, $categoryId, $clientId)->distinct()->count('i.id');
    }

    protected function invoiceQuery(Carbon $from, Carbon $to, ?int $categoryId, ?int $clientId): Builder
    {
        $query = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->leftJoin('products as p', function ($join) {
                $join->on('p.id', '=', 'ii.product_id')->where('p.company_id', '=', $this->companyId);
            })
            ->where('i.company_id', $this->companyId)
            ->where('ii.type', 'item')
            ->whereIn('i.status', self::STATUSES)
            ->whereBetween('i.issue_date', [$from->toDateString(), $to->toDateString()]);

        if ($clientId) {
            $query->where('i.client_id', $clientId);
        }

        if ($categoryId) {
            $query->where('p.category_id', $categoryId);
        }

        return $query;
    }

    /**
     * Credit note lines of the credit notes issued in the period, per product id.
     *
     * @return array<int, array{credited: float}>
     */
    protected function creditedLines(Carbon $from, Carbon $to, ?int $categoryId, ?int $clientId): array
    {
        $factor = $this->discountFactor('cn') . ' * ' . $this->fxFactor('cn.currency_id', 'inv.exchange_rate');

        $query = DB::table('credit_note_items as ci')
            ->join('credit_notes as cn', 'cn.id', '=', 'ci.credit_note_id')
            ->leftJoin('invoices as inv', 'inv.id', '=', 'cn.invoice_id')
            ->leftJoin('products as p', function ($join) {
                $join->on('p.id', '=', 'ci.product_id')->where('p.company_id', '=', $this->companyId);
            })
            ->where('cn.company_id', $this->companyId)
            ->whereNull('cn.deleted_at')
            ->where('ci.type', 'item')
            ->whereBetween('cn.issue_date', [$from->toDateString(), $to->toDateString()]);

        if ($clientId) {
            $query->where('cn.client_id', $clientId);
        }

        if ($categoryId) {
            $query->where('p.category_id', $categoryId);
        }

        return $query->groupBy('ci.product_id')
            ->selectRaw("COALESCE(ci.product_id, 0) as pid, SUM(ci.amount * {$factor}) as amount")
            ->get()
            ->mapWithKeys(fn($r) => [(int)$r->pid => ['credited' => (float)$r->amount]])
            ->all();
    }

    /** SQL: 1 - invoice level discount ratio. */
    protected function discountFactor(string $alias): string
    {
        return "(1 - CASE WHEN {$alias}.discount_type = 'percent' THEN LEAST(COALESCE({$alias}.discount, 0), 100) / 100"
            . " WHEN COALESCE({$alias}.sub_total, 0) > 0 THEN LEAST(COALESCE({$alias}.discount, 0) / {$alias}.sub_total, 1) ELSE 0 END)";
    }

    /** SQL: exchange rate to the company currency (1 for the company currency). */
    protected function fxFactor(string $currencyColumn, string $rateColumn): string
    {
        if ($this->currencyId === 0) {
            return '1';
        }

        // BGN <-> EUR: the fixed legal rate, whatever the document recorded (like InvoiceExpenseSync::toBase())
        $peg = '';
        $pegged = ['EUR' => ['BGN', 1 / \App\Services\Databackd\Data\FinEntryImporter::BGN_PER_EUR], 'BGN' => ['EUR', \App\Services\Databackd\Data\FinEntryImporter::BGN_PER_EUR]];

        if (isset($pegged[$this->currencyCode])) {
            [$other, $rate] = $pegged[$this->currencyCode];
            $ids = DB::table('currencies')->where('company_id', $this->companyId)->where('currency_code', $other)->pluck('id')->map(fn($id) => (int)$id)->all();

            if ($ids) {
                $peg = " WHEN {$currencyColumn} IN (" . implode(',', $ids) . ") THEN " . sprintf('%.10F', $rate);
            }
        }

        return "(CASE WHEN {$currencyColumn} IS NULL OR {$currencyColumn} = {$this->currencyId} THEN 1{$peg} WHEN COALESCE({$rateColumn}, 0) <= 0 THEN 1 ELSE {$rateColumn} END)";
    }

    /** Category with the same name as a fin_dimensions product group. */
    protected function categoryOfProductGroup(int $dimensionId): ?int
    {
        $name = FinDimension::withoutGlobalScopes()->where('company_id', $this->companyId)->where('type', 'product_group')->where('id', $dimensionId)->value('name');

        if (!$name) {
            return null;
        }

        $id = DB::table('product_category')->where('company_id', $this->companyId)->where('category_name', $name)->value('id');

        return $id ? (int)$id : null;
    }

    /**
     * @return array{products: int, without_cost: int}
     */
    protected function catalog(): array
    {
        $row = DB::table('products')->where('company_id', $this->companyId)
            ->selectRaw('COUNT(*) as total, SUM(cost_price IS NULL) as without_cost')->first();

        return ['products' => (int)($row->total ?? 0), 'without_cost' => (int)($row->without_cost ?? 0)];
    }

}
