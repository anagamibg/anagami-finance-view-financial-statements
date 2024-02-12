@extends('layouts.app')

@push('styles')
    @include('databackd.gaps.margins._styles')
@endpush

@php
    $t = $report['totals'];
    $compare = $report['compare'];
    $changes = $compare['changes'] ?? [];
    $hasCompare = $compare !== null;
    $rows = $report['rows'];
    $groups = $report['groups'];
    $nb = "\u{00A0}";
    $cur = $currencySymbol;

    $num = fn($v, $d = 2) => number_format((float)$v, $d, ',', $nb);
    $money = fn($v) => $v === null ? '—' : $num($v);
    $moneyC = fn($v) => $v === null ? '—' : $num($v) . $nb . $cur;
    $qty = fn($v) => $num($v, abs((float)$v - round((float)$v)) < 0.005 ? 0 : 2);
    $pct = fn($v, $d = 1) => $v === null ? '—' : $num($v, $d) . $nb . '%';
    $signed = fn($v, $unit = '%') => $v === null ? '—' : ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . $num(abs($v), 1) . $nb . $unit;
    $tone = fn($v) => $v === null ? '' : ($v > 0.004 ? 'pos' : ($v < -0.004 ? 'neg' : ''));

    $missingProducts = array_values(array_filter($rows, fn($r) => !$r['has_cost'] && $r['product_id']));
    $missingRevenue = array_sum(array_column($missingProducts, 'revenue'));
    $productCount = count(array_filter($rows, fn($r) => $r['product_id']));
    $allMissing = $productCount > 0 && count($missingProducts) === $productCount && $t['costed_count'] === 0;

    $productUrl = function ($row) use ($canEditAll, $canEditOwn, $canView, $userId) {
        if (!$row['product_id']) {
            return null;
        }

        if ($canEditAll || ($canEditOwn && $row['added_by'] == $userId)) {
            return route('products.edit', $row['product_id']);
        }

        return $canView ? route('products.show', $row['product_id']) : null;
    };
@endphp

@section('content')
    <div class="content-wrapper mg-report">

        <div class="d-none d-print-block mb-3">
            <h4 class="mb-1">@lang('databackd-gaps-margins.title')</h4>
            <div class="f-12 text-dark-grey">
                @foreach ($filters->summary() as $line)
                    {{ $line }}<br>
                @endforeach
                @if ($report['filters']['category'])
                    @lang('databackd-gaps-margins.filters.category'): {{ $report['filters']['category'] }}<br>
                @endif
            </div>
        </div>

        <x-dbk.page-header :subtitle="__('databackd-gaps-margins.subtitle')" class="mg-noprint">
            <x-slot name="actions">
                <x-forms.link-secondary :link="route('databackd.gaps.margins.excel', $query)" icon="file-excel" class="mr-2">
                    @lang('databackd-gaps-margins.actions.excel')
                </x-forms.link-secondary>
                <x-forms.link-secondary :link="route('databackd.gaps.margins.pdf', $query)" icon="file-pdf" class="mr-2">
                    @lang('databackd-gaps-margins.actions.pdf')
                </x-forms.link-secondary>
                <x-forms.button-secondary id="mg-print" icon="print">@lang('databackd-gaps-margins.actions.print')</x-forms.button-secondary>
            </x-slot>
        </x-dbk.page-header>

        @include('databackd.gaps.margins._filters', ['filters' => $filters, 'categoryId' => $categoryId, 'categories' => $categoryOptions, 'clients' => $clientOptions])

        @if ($report['notes']['group_unmatched'])
            <div class="alert alert-warning mg-noprint">@lang('databackd-gaps-margins.alerts.group_unmatched')</div>
        @endif
        @if ($report['notes']['entity_ignored'])
            <div class="alert alert-info mg-noprint">@lang('databackd-gaps-margins.alerts.entity_ignored')</div>
        @endif

        @if (empty($rows))
            <div class="dbk-card">
                <div class="dbk-card-body">
                    <x-dbk.empty icon="box-seam" :title="__('databackd-gaps-margins.empty.title')" :text="__('databackd-gaps-margins.empty.text')" />
                </div>
            </div>
        @else
            {{-- Missing cost price warning --}}
            @if ($missingProducts)
                <div class="alert alert-warning mg-alert mg-noprint" role="alert">
                    <i class="bi bi-exclamation-triangle mg-alert-icon" aria-hidden="true"></i>
                    <div>
                        @if ($allMissing)
                            <div class="f-w-600">@lang('databackd-gaps-margins.alerts.missing_title', ['count' => count($missingProducts), 'total' => $productCount])</div>
                            <div>@lang('databackd-gaps-margins.alerts.missing_all')</div>
                        @else
                            <div class="f-w-600">@lang('databackd-gaps-margins.alerts.missing_title', ['count' => count($missingProducts), 'total' => $productCount])</div>
                            <div>@lang('databackd-gaps-margins.alerts.missing_text', ['pct' => $num($t['revenue'] > 0 ? $missingRevenue / $t['revenue'] * 100 : 0, 1), 'amount' => $moneyC($missingRevenue)])</div>
                        @endif
                        <div class="mg-alert-products">
                            @foreach (array_slice($missingProducts, 0, 6) as $mp)
                                @php $url = $productUrl($mp); @endphp
                                @if ($url)
                                    <a href="{{ $url }}" class="dbk-chip" title="@lang('databackd-gaps-margins.alerts.set_cost')"><i class="bi bi-pencil"></i>{{ $mp['name'] }}</a>
                                @else
                                    <span class="dbk-chip">{{ $mp['name'] }}</span>
                                @endif
                            @endforeach
                            @if (count($missingProducts) > 6)
                                <span class="f-12 text-dark-grey align-self-center">@lang('databackd-gaps-margins.alerts.more', ['count' => count($missingProducts) - 6])</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- KPI --}}
            <div class="dbk-kpi-grid">
                <x-dbk.kpi :label="__('databackd-gaps-margins.kpi.revenue')" :value="$moneyC($t['revenue'])" icon="bi-cash-stack" tone="revenue"
                    :change="$changes['revenue_pct'] ?? null" good="up"
                    :hint="$hasCompare ? __('databackd-gaps-margins.vs_previous') : __('databackd-gaps-margins.kpi.revenue_hint', ['count' => $t['invoices']])" />
                <x-dbk.kpi :label="__('databackd-gaps-margins.kpi.cost')" :value="$t['costed_count'] ? $moneyC($t['cost']) : '—'" icon="bi-box-seam" tone="expense"
                    :change="$changes['cost_pct'] ?? null" good="down"
                    :hint="$hasCompare && ($changes['cost_pct'] ?? null) !== null ? __('databackd-gaps-margins.vs_previous') : ($t['costed_count'] ? __('databackd-gaps-margins.kpi.cost_hint') : __('databackd-gaps-margins.kpi.no_cost'))" />
                <x-dbk.kpi :label="__('databackd-gaps-margins.kpi.margin')" :value="$moneyC($t['margin'])" icon="bi-graph-up-arrow" tone="profit"
                    :change="$changes['margin_pct'] ?? null" good="up"
                    :hint="$hasCompare && ($changes['margin_pct'] ?? null) !== null ? __('databackd-gaps-margins.vs_previous') : __('databackd-gaps-margins.kpi.margin_hint')" />
                <x-dbk.kpi :label="__('databackd-gaps-margins.kpi.margin_pct')" :value="$pct($t['margin_pct'])" icon="bi-percent" tone="cash"
                    :change="$changes['margin_pp'] ?? null" :change-unit="__('databackd-gaps-margins.pp')" good="up"
                    :hint="$hasCompare && ($changes['margin_pp'] ?? null) !== null ? __('databackd-gaps-margins.vs_previous') : ($t['coverage_pct'] !== null ? __('databackd-gaps-margins.kpi.coverage', ['pct' => $num($t['coverage_pct'], 0)]) : null)" />
            </div>

            {{-- Charts --}}
            <div class="dbk-grid mb-3">
                <div class="dbk-span-8">
                    <x-dbk.card :title="__('databackd-gaps-margins.charts.products')" :subtitle="__('databackd-gaps-margins.charts.products_sub')" class="h-100">
                        <x-dbk.chart id="mg-chart-products" size="lg" :label="__('databackd-gaps-margins.charts.products')" />
                    </x-dbk.card>
                </div>
                <div class="dbk-span-4">
                    <x-dbk.card :title="__('databackd-gaps-margins.charts.groups_share')" class="h-100">
                        <x-dbk.chart id="mg-chart-groups" size="lg" :label="__('databackd-gaps-margins.charts.groups_share')" />
                    </x-dbk.card>
                </div>
                <div class="dbk-span-12">
                    <x-dbk.card :title="__('databackd-gaps-margins.charts.groups_compare')">
                        <x-dbk.chart id="mg-chart-group-bars" :label="__('databackd-gaps-margins.charts.groups_compare')" />
                    </x-dbk.card>
                </div>
            </div>

            {{-- By product group --}}
            <x-dbk.card :title="__('databackd-gaps-margins.groups.title')" :header-border="true" class="mb-3" body-class="p-0">
                <div class="dbk-table-wrap is-flush">
                    <table class="dbk-table dbk-table--compact dbk-table--sticky-first mg-table" id="mg-groups-table">
                        <thead>
                        <tr>
                            <th class="mg-sortable" data-type="text">@lang('databackd-gaps-margins.table.columns.group')</th>
                            <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.revenue')</th>
                            <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.cost')</th>
                            <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.margin')</th>
                            <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.margin_pct')</th>
                            <th class="mg-sortable" data-type="num">@lang('databackd-gaps-margins.table.columns.share')</th>
                            @if ($hasCompare)
                                <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.prev_margin')</th>
                                <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.change')</th>
                            @endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($groups as $g)
                            <tr>
                                <td data-v="{{ mb_strtolower($g['name']) }}">
                                    @if ($g['id'])
                                        <a href="{{ route('databackd.gaps.margins.index', array_merge($query, ['category_id' => $g['id']])) }}" class="f-w-500">{{ $g['name'] }}</a>
                                    @else
                                        <span class="f-w-500">{{ $g['name'] }}</span>
                                    @endif
                                    <span class="mg-sub">
                                        @lang('databackd-gaps-margins.groups.count', ['count' => $g['products']])
                                        @if ($g['missing_count'] > 0)
                                            · <span class="dbk-text-warning" title="@lang('databackd-gaps-margins.table.no_cost_hint')"><i class="bi bi-exclamation-triangle"></i> @lang('databackd-gaps-margins.table.no_cost'): {{ $g['missing_count'] }}</span>
                                        @endif
                                    </span>
                                </td>
                                <td class="num" data-v="{{ $g['revenue'] }}">{{ $money($g['revenue']) }}</td>
                                <td class="num" data-v="{{ $g['cost'] ?? '' }}">{{ $money($g['cost']) }}</td>
                                <td class="num {{ $tone($g['margin']) }}" data-v="{{ $g['margin'] ?? '' }}">{{ $money($g['margin']) }}</td>
                                <td class="num {{ $tone($g['margin_pct']) }}" data-v="{{ $g['margin_pct'] ?? '' }}">{{ $pct($g['margin_pct']) }}</td>
                                <td data-v="{{ $g['share'] }}">
                                    <div class="mg-share">
                                        <div class="dbk-progress"><span style="width: {{ max(0, min(100, $g['share'])) }}%"></span></div>
                                        <span class="f-13 dbk-num">{{ $pct($g['share']) }}</span>
                                    </div>
                                </td>
                                @if ($hasCompare)
                                    <td class="num" data-v="{{ $g['prev_margin'] ?? '' }}">{{ $money($g['prev_margin'] ?? null) }}</td>
                                    <td class="num {{ $tone($g['margin_change_pct'] ?? null) }}" data-v="{{ $g['margin_change_pct'] ?? '' }}">{{ $signed($g['margin_change_pct'] ?? null) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-dbk.card>

            {{-- By product --}}
            <x-dbk.card :title="__('databackd-gaps-margins.table.title') . ' (' . count($rows) . ')'" :header-border="true" class="mb-3" body-class="p-0">
                <x-slot name="actions">
                    <div class="input-group input-group-sm mg-search mg-noprint">
                        <div class="input-group-prepend"><span class="input-group-text border-0 bg-additional-grey"><i class="fa fa-search f-13 text-dark-grey"></i></span></div>
                        <input type="text" id="mg-search" class="form-control f-14" placeholder="@lang('databackd-gaps-margins.table.search')">
                    </div>
                </x-slot>

                <div class="dbk-table-wrap dbk-table-wrap--scroll is-flush">
                    <table class="dbk-table dbk-table--sticky dbk-table--sticky-first mg-table" id="mg-products-table">
                        <thead>
                        <tr>
                            <th class="mg-sortable" data-type="text">@lang('databackd-gaps-margins.table.columns.product')</th>
                            <th class="mg-sortable" data-type="text">@lang('databackd-gaps-margins.table.columns.group')</th>
                            <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.quantity')</th>
                            <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.revenue')</th>
                            <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.cost')</th>
                            <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.margin')</th>
                            <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.margin_pct')</th>
                            <th class="mg-sortable" data-type="num">@lang('databackd-gaps-margins.table.columns.share')</th>
                            @if ($hasCompare)
                                <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.prev_margin')</th>
                                <th class="mg-sortable num" data-type="num">@lang('databackd-gaps-margins.table.columns.change')</th>
                            @endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($rows as $r)
                            @php $url = $productUrl($r); @endphp
                            <tr data-name="{{ mb_strtolower($r['name'] . ' ' . $r['group'] . ' ' . $r['sku']) }}" @class(['mg-missing' => !$r['has_cost']])>
                                <td class="mg-name" data-v="{{ mb_strtolower($r['name']) }}">
                                    @if ($r['product_id'])
                                        @if ($url)
                                            <a href="{{ $url }}" class="f-w-500">{{ $r['name'] }}</a>
                                        @else
                                            <span class="f-w-500">{{ $r['name'] }}</span>
                                        @endif
                                        @if ($r['sku'])
                                            <span class="mg-sub">{{ $r['sku'] }}</span>
                                        @endif
                                    @else
                                        <span class="f-w-500 text-dark-grey">{{ __('databackd-gaps-margins.table.unlinked') }}</span>
                                    @endif
                                    @if ($r['credited'] > 0)
                                        <span class="mg-sub">@lang('databackd-gaps-margins.table.credited', ['amount' => $moneyC($r['credited'])])</span>
                                    @endif
                                </td>
                                <td data-v="{{ mb_strtolower($r['group']) }}">{{ $r['group'] }}</td>
                                <td class="num" data-v="{{ $r['quantity'] }}">{{ $qty($r['quantity']) }}</td>
                                <td class="num" data-v="{{ $r['revenue'] }}">{{ $money($r['revenue']) }}</td>
                                @if ($r['has_cost'])
                                    <td class="num" data-v="{{ $r['cost'] }}">{{ $money($r['cost']) }}</td>
                                    <td class="num {{ $tone($r['margin']) }}" data-v="{{ $r['margin'] }}">{{ $money($r['margin']) }}</td>
                                    <td class="num {{ $tone($r['margin']) }}" data-v="{{ $r['margin_pct'] ?? '' }}">{{ $pct($r['margin_pct']) }}</td>
                                @else
                                    <td class="num" data-v="">
                                        @if ($r['product_id'] && $url)
                                            <a href="{{ $url }}" class="dbk-badge is-warning no-dot" title="@lang('databackd-gaps-margins.table.no_cost_hint')"><i class="bi bi-exclamation-triangle"></i> @lang('databackd-gaps-margins.table.no_cost')</a>
                                        @else
                                            <span class="dbk-badge is-warning no-dot" title="@lang('databackd-gaps-margins.table.no_cost_hint')"><i class="bi bi-exclamation-triangle"></i> @lang('databackd-gaps-margins.table.no_cost')</span>
                                        @endif
                                    </td>
                                    <td class="num text-lightest" data-v="">—</td>
                                    <td class="num text-lightest" data-v="">—</td>
                                @endif
                                <td data-v="{{ $r['share'] }}">
                                    <div class="mg-share">
                                        <div class="dbk-progress"><span style="width: {{ max(0, min(100, $r['share'] * 2.5)) }}%"></span></div>
                                        <span class="f-13 dbk-num">{{ $pct($r['share']) }}</span>
                                    </div>
                                </td>
                                @if ($hasCompare)
                                    <td class="num" data-v="{{ $r['prev_margin'] ?? '' }}">{{ $money($r['prev_margin'] ?? null) }}</td>
                                    <td class="num {{ $tone($r['margin_change_pct'] ?? null) }}" data-v="{{ $r['margin_change_pct'] ?? '' }}">{{ $signed($r['margin_change_pct'] ?? null) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr class="dbk-row-total">
                            <td colspan="2">@lang('databackd-gaps-margins.table.total')</td>
                            <td class="num">{{ $qty($t['quantity']) }}</td>
                            <td class="num">{{ $money($t['revenue']) }}</td>
                            <td class="num">{{ $t['costed_count'] ? $money($t['cost']) : '—' }}</td>
                            <td class="num {{ $tone($t['margin']) }}">{{ $money($t['margin']) }}</td>
                            <td class="num {{ $tone($t['margin']) }}">{{ $pct($t['margin_pct']) }}</td>
                            <td><span class="f-13 dbk-num">100,0{{ $nb }}%</span></td>
                            @if ($hasCompare)
                                <td class="num">{{ $money($compare['totals']['margin']) }}</td>
                                <td class="num {{ $tone($changes['margin_pct'] ?? null) }}">{{ $signed($changes['margin_pct'] ?? null) }}</td>
                            @endif
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </x-dbk.card>

            <div class="f-12 text-dark-grey mb-3">
                <div class="f-w-600 mb-1">@lang('databackd-gaps-margins.notes.title')</div>
                <ul class="pl-3 mb-2">
                    <li>@lang('databackd-gaps-margins.notes.revenue')</li>
                    <li>@lang('databackd-gaps-margins.notes.cost')</li>
                    <li>@lang('databackd-gaps-margins.notes.group')</li>
                </ul>
                @if ($hasCompare)
                    <div>@lang('databackd-gaps-margins.compare_period', ['from' => $compare['from']->format('d.m.Y'), 'to' => $compare['to']->format('d.m.Y')])</div>
                @endif
                <div>@lang('databackd-gaps-margins.currency_note', ['currency' => $report['currency']]) · @lang('databackd-gaps-margins.notes.data_as_of') {{ $generatedAt->format('d.m.Y H:i') }}</div>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('#mg-print').on('click', function () { window.print(); });

            // Live search in the products table
            $('#mg-search').on('input', function () {
                var q = $.trim($(this).val()).toLowerCase();
                $('#mg-products-table tbody tr').each(function () {
                    $(this).toggle(String($(this).data('name')).indexOf(q) !== -1);
                });
            });

            // Client-side sorting of both tables (empty values always sort as the smallest)
            $('table.mg-table th.mg-sortable').on('click', function () {
                var $th = $(this), $table = $th.closest('table'), idx = $th.index(), type = $th.data('type');
                var dir = $th.hasClass('mg-asc') ? -1 : 1;
                $table.find('th').removeClass('mg-asc mg-desc');
                $th.addClass(dir === 1 ? 'mg-asc' : 'mg-desc');
                var rows = $table.find('tbody tr').get();
                rows.sort(function (a, b) {
                    var x = $(a).children().eq(idx).data('v'), y = $(b).children().eq(idx).data('v');
                    if (type === 'num') {
                        x = x === '' || x === undefined ? -1e18 : parseFloat(x);
                        y = y === '' || y === undefined ? -1e18 : parseFloat(y);
                        return (x - y) * dir;
                    }
                    return String(x).localeCompare(String(y), 'bg') * dir;
                });
                $.each(rows, function (i, tr) { $table.find('tbody').append(tr); });
            });

            @if (!empty($rows))
            if (!window.dbkChart) { return; }

            var C = @json($charts);
            var f = dbkChart.fmt;
            var L = {
                margin: @json(__('databackd-gaps-margins.charts.margin')),
                revenue: @json(__('databackd-gaps-margins.charts.revenue')),
                cost: @json(__('databackd-gaps-margins.charts.cost')),
                marginPct: @json(__('databackd-gaps-margins.table.columns.margin_pct')),
                noMargin: @json(__('databackd-gaps-margins.charts.no_margin'))
            };

            // Gross margin by product (top 10)
            dbkChart.bar('mg-chart-products', {
                labels: C.products.labels, horizontal: true, legend: false, unit: 'money', emptyText: L.noMargin,
                datasets: [{label: L.margin, data: C.products.margin, role: 'profit'}],
                options: {plugins: {tooltip: {callbacks: {afterLabel: function (ctx) {
                    var p = C.products.margin_pct[ctx.dataIndex];
                    return p === null || p === undefined ? '' : ' ' + L.marginPct + ': ' + f.percent(p);
                }}}}}
            });

            // Revenue share by product group
            var totalRevenue = C.groups.revenue.reduce(function (s, v) { return s + (Number(v) || 0); }, 0);
            dbkChart.donut('mg-chart-groups', {
                labels: C.groups.labels, data: C.groups.revenue, unit: 'money',
                center: {label: L.revenue, value: f.compactMoney(totalRevenue)}
            });

            // Revenue / cost / margin by product group
            dbkChart.bar('mg-chart-group-bars', {
                labels: C.groups.labels, unit: 'money',
                datasets: [
                    {label: L.revenue, data: C.groups.revenue, role: 'revenue'},
                    {label: L.cost, data: C.groups.cost, role: 'expense'},
                    {label: L.margin, data: C.groups.margin, role: 'profit'}
                ]
            });
            @endif
        });
    </script>
@endpush
