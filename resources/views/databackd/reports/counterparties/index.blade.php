@extends('layouts.app')

@php
    use App\Exports\Databackd\Reports\CounterpartyData;

    $money = fn($v) => number_format((float)$v, 2, ',', ' ');
    $pct = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', ' ') . ' %';
    $hasCompare = !empty($report['compare']);
    $isAll = $side === 'all';
    $rows = $report['rows'];
    $kpi = $report['kpi'];
    $top10 = array_slice(array_values(array_filter($rows, fn($r) => !$r['is_none'])), 0, 10);
    $docsHint = __('databackd-reports-counterparties.docs_hint');
    $cur = __('databackd-reports-common.currency');
    $sideRole = ['revenue' => 'revenue', 'expense' => 'expense'][$side] ?? 'profit';
@endphp

@section('content')
    <div class="content-wrapper dbk-reports cp-report">

        <div class="d-none d-print-block mb-3">
            <h4 class="mb-1">@lang('databackd-reports-counterparties.title') — @lang('databackd-reports-counterparties.sides.' . $side)</h4>
            <div class="f-12 text-dark-grey">
                @foreach ($filters->summary() as $line)
                    {{ $line }}<br>
                @endforeach
            </div>
        </div>

        <x-dbk.page-header class="cp-noprint" :title="__('databackd-reports-counterparties.title')">
            <div class="dbk-segmented mt-2" role="group" aria-label="{{ __('databackd-misc.counterparty_side') }}">
                @foreach (CounterpartyData::SIDES as $s)
                    <a href="{{ route('databackd.reports.counterparties.index', array_merge($query, ['side' => $s])) }}"
                        @class(['is-active' => $side === $s]) @if ($side === $s) aria-current="page" @endif>@lang('databackd-reports-counterparties.sides.' . $s)</a>
                @endforeach
            </div>
            <x-slot name="actions">
                <div class="dbk-reports-actions">
                    @includeIf('databackd.reports.saved._save-button', ['type' => 'counterparties', 'config' => ['filters' => $filters->toQuery(), 'side' => $side]])
                    <div class="btn-group" role="group">
                        <a href="{{ route('databackd.reports.counterparties.excel', $query) }}" class="btn btn-secondary btn-sm"><i class="fa fa-file-excel mr-1"></i>@lang('databackd-reports-counterparties.excel')</a>
                        <a href="{{ route('databackd.reports.counterparties.pdf', $query) }}" class="btn btn-secondary btn-sm"><i class="fa fa-file-pdf mr-1"></i>@lang('databackd-reports-counterparties.pdf')</a>
                        <button type="button" class="btn btn-secondary btn-sm" id="cp-print"><i class="fa fa-print mr-1"></i>@lang('databackd-reports-counterparties.print')</button>
                    </div>
                </div>
            </x-slot>
        </x-dbk.page-header>

        <div class="cp-noprint">
            @include('databackd.reports._filters', ['filters' => $filters, 'options' => ['period', 'entities', 'dimensions', 'client', 'compare'], 'action' => route('databackd.reports.counterparties.index'), 'hidden' => ['side' => $side]])
        </div>

        @if (empty($rows))
            <x-dbk.empty class="dbk-card" icon="people" :text="__('databackd-reports-common.no_data')" />
        @else
            {{-- KPI --}}
            <div class="dbk-kpi-grid">
                <x-dbk.kpi :label="__('databackd-reports-counterparties.kpi.count')" :value="number_format($kpi['count'], 0, ',', ' ')" icon="bi-people" />
                <x-dbk.kpi :label="__('databackd-reports-counterparties.kpi.total')" :value="$money($kpi['total']) . ' ' . $cur" icon="bi-cash-stack" :tone="$sideRole === 'profit' ? null : $sideRole"
                    :change="$hasCompare ? ($report['totals']['change_pct'] ?? null) : null" :good="$side === 'expense' ? 'down' : 'up'"
                    :hint="$hasCompare ? __('databackd-reports-counterparties.vs_previous') : null" />
                <x-dbk.kpi :label="__('databackd-reports-counterparties.kpi.top5')" :value="$pct($kpi['top5_share'])" icon="bi-pie-chart" />
                <x-dbk.kpi :label="__('databackd-reports-counterparties.kpi.largest')" :value="$kpi['largest'] ? $kpi['largest']['label'] : '—'" icon="bi-trophy"
                    class="cp-kpi-largest"
                    :hint="$kpi['largest'] ? $money($kpi['largest']['total']) . ' ' . $cur . ' · ' . $pct($kpi['largest']['share']) : null" />
            </div>

            {{-- Charts --}}
            @if ($top10)
                <div class="dbk-grid mb-3 cp-noprint-chart">
                    <x-dbk.card class="dbk-reports-span-7" :title="__('databackd-reports-counterparties.chart_top10')">
                        <x-dbk.chart id="cp-bar" :label="__('databackd-reports-counterparties.chart_top10')" />
                    </x-dbk.card>
                    <x-dbk.card class="dbk-reports-span-5" :title="__('databackd-reports-counterparties.chart_share')">
                        <x-dbk.chart id="cp-donut" class="cp-donut-box" :label="__('databackd-reports-counterparties.chart_share')" />
                    </x-dbk.card>
                </div>
            @endif

            {{-- Table --}}
            <x-dbk.card class="mb-3" body-class="p-0" header-border
                :title="__('databackd-reports-counterparties.sides.' . $side) . ' (' . count($rows) . ')'">
                <x-slot name="actions">
                    <div class="dbk-reports-search cp-noprint">
                        <i class="fa fa-search" aria-hidden="true"></i>
                        <input type="text" id="cp-search" class="form-control form-control-sm" placeholder="@lang('databackd-reports-counterparties.search_placeholder')">
                    </div>
                </x-slot>

                <div class="dbk-table-wrap dbk-table-wrap--scroll is-flush">
                    <table class="dbk-table dbk-table--striped dbk-table--sticky dbk-table--sticky-first" id="cp-table">
                        <thead>
                        <tr>
                            <th class="cp-sortable" data-type="text">@lang('databackd-reports-counterparties.columns.counterparty')</th>
                            <th class="cp-sortable num" data-type="num">@lang('databackd-reports-counterparties.columns.turnover')</th>
                            @if ($isAll)
                                <th class="cp-sortable num" data-type="num">@lang('databackd-reports-counterparties.columns.revenue')</th>
                                <th class="cp-sortable num" data-type="num">@lang('databackd-reports-counterparties.columns.expense')</th>
                            @endif
                            <th class="cp-sortable num" data-type="num" title="{{ $docsHint }}">@lang('databackd-reports-counterparties.columns.documents')</th>
                            <th class="cp-sortable num" data-type="num">@lang('databackd-reports-counterparties.columns.avg_document')</th>
                            <th class="cp-sortable" data-type="num" style="min-width: 150px">@lang('databackd-reports-counterparties.columns.share')</th>
                            <th class="cp-sortable" data-type="num" title="@lang('databackd-reports-counterparties.trend_hint')">@lang('databackd-reports-counterparties.columns.trend')</th>
                            @if ($hasCompare)
                                <th class="cp-sortable num" data-type="num">@lang('databackd-reports-counterparties.columns.previous')</th>
                                <th class="cp-sortable num" data-type="num">@lang('databackd-reports-counterparties.columns.change')</th>
                            @endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($rows as $r)
                            <tr data-name="{{ mb_strtolower($r['label']) }}">
                                <td class="cp-name" data-v="{{ mb_strtolower($r['label']) }}">
                                    <a href="{{ route('databackd.reports.counterparties.show', array_merge($query, ['name' => $r['key']])) }}"
                                        @class(['cp-link', 'is-none' => $r['is_none']])>{{ $r['label'] }}</a>
                                </td>
                                <td @class(['num f-w-500', 'neg' => $r['total'] < 0]) data-v="{{ $r['total'] }}">{{ $money($r['total']) }}</td>
                                @if ($isAll)
                                    <td @class(['num', 'neg' => $r['revenue'] < 0]) data-v="{{ $r['revenue'] }}">{{ $money($r['revenue']) }}</td>
                                    <td @class(['num', 'neg' => $r['expense'] < 0]) data-v="{{ $r['expense'] }}">{{ $money($r['expense']) }}</td>
                                @endif
                                <td class="num" data-v="{{ $r['docs'] }}">{{ number_format($r['docs'], 0, ',', ' ') }}</td>
                                <td @class(['num', 'neg' => $r['avg'] < 0]) data-v="{{ $r['avg'] }}">{{ $money($r['avg']) }}</td>
                                <td data-v="{{ $r['share'] }}">
                                    <div class="cp-share">
                                        <div class="cp-bar"><i style="width: {{ max(0, min(100, $r['share'] * 2.5)) }}%"></i></div>
                                        <span class="dbk-num">{{ $pct($r['share']) }}</span>
                                    </div>
                                </td>
                                <td data-v="{{ $r['trend_pct'] ?? 0 }}"
                                    title="{{ __('databackd-reports-counterparties.trend_' . $r['trend']) }}{{ $r['trend_pct'] !== null ? ': ' . $pct($r['trend_pct']) : '' }}">
                                    {!! CounterpartyData::sparkline($r['spark'], $r['trend']) !!}
                                </td>
                                @if ($hasCompare)
                                    <td @class(['num', 'neg' => ($r['previous'] ?? 0) < 0]) data-v="{{ $r['previous'] ?? 0 }}">{{ $money($r['previous'] ?? 0) }}</td>
                                    <td class="num {{ ($r['change_pct'] ?? 0) > 0 ? 'pos' : (($r['change_pct'] ?? 0) < 0 ? 'neg' : '') }}"
                                        data-v="{{ $r['change_pct'] ?? 0 }}">{{ $pct($r['change_pct'] ?? null) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr class="dbk-row-total">
                            <td>@lang('databackd-reports-common.total')</td>
                            <td class="num">{{ $money($report['totals']['total']) }}</td>
                            @if ($isAll)
                                <td class="num">{{ $money($report['totals']['revenue']) }}</td>
                                <td class="num">{{ $money($report['totals']['expense']) }}</td>
                            @endif
                            <td class="num">{{ number_format($report['totals']['docs'], 0, ',', ' ') }}</td>
                            <td class="num">{{ $money($report['totals']['avg']) }}</td>
                            <td class="dbk-num">100,0 %</td>
                            <td></td>
                            @if ($hasCompare)
                                <td class="num">{{ $money($report['totals']['previous'] ?? 0) }}</td>
                                <td class="num">{{ $pct($report['totals']['change_pct'] ?? null) }}</td>
                            @endif
                        </tr>
                        </tfoot>
                    </table>
                </div>
                <x-slot name="footer">
                    <span class="dbk-reports-hint m-0">{{ $docsHint }} · @lang('databackd-reports-common.data_as_of') {{ $generatedAt->format('d.m.Y H:i') }}</span>
                </x-slot>
            </x-dbk.card>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('#cp-print').on('click', function () { window.print(); });

            // Live search
            $('#cp-search').on('input', function () {
                var q = $.trim($(this).val()).toLowerCase();
                $('#cp-table tbody tr').each(function () {
                    $(this).toggle(String($(this).data('name')).indexOf(q) !== -1);
                });
            });

            // Client-side sorting
            $('#cp-table th.cp-sortable').on('click', function () {
                var $th = $(this), idx = $th.index(), type = $th.data('type');
                var dir = $th.hasClass('cp-asc') ? -1 : 1;
                $('#cp-table th').removeClass('cp-asc cp-desc');
                $th.addClass(dir === 1 ? 'cp-asc' : 'cp-desc');
                var $rows = $('#cp-table tbody tr').get();
                $rows.sort(function (a, b) {
                    var x = $(a).children().eq(idx).data('v'), y = $(b).children().eq(idx).data('v');
                    if (type === 'num') { return (parseFloat(x) - parseFloat(y)) * dir; }
                    return String(x).localeCompare(String(y), 'bg') * dir;
                });
                $.each($rows, function (i, tr) { $('#cp-table tbody').append(tr); });
            });

            @if (!empty($top10))
            var labels = @json(array_column($top10, 'label'));
            var values = @json(array_column($top10, 'total'));
            var shares = @json(array_column($top10, 'share'));
            var otherLabel = @json(__('databackd-reports-common.other'));
            var maxLen = window.innerWidth < 576 ? 16 : 26;
            function short(s) { return s.length > maxLen ? s.substring(0, maxLen - 1) + '…' : s; }

            // Ranking → horizontal bars, one colour (the side of the report)
            dbkChart.bar('cp-bar', {
                labels: labels.map(short),
                datasets: [{label: @json(__('databackd-reports-counterparties.columns.share')), data: shares, role: @json($sideRole)}],
                horizontal: true,
                unit: 'percent',
                legend: false,
                options: {
                    scales: {y: {ticks: {autoSkip: false}}},
                    plugins: {tooltip: {callbacks: {
                        title: function (items) { return labels[items[0].dataIndex]; },
                        label: function (c) { return ' ' + dbkChart.fmt.money(values[c.dataIndex]) + ' (' + dbkChart.fmt.percent(c.parsed.x) + ')'; }
                    }}}
                }
            });

            // Structure: the top 7 + "Other" in the fixed categorical order
            var parts = dbkChart.topN(labels.map(function (l, i) { return {label: l, value: values[i]}; }), 8, otherLabel);
            dbkChart.donut('cp-donut', {
                labels: parts.map(function (p) { return short(p.label); }),
                data: parts.map(function (p) { return p.value; }),
                legendPosition: window.innerWidth < 1200 ? 'bottom' : 'right',
                options: {
                    plugins: {tooltip: {callbacks: {
                        label: function (c) { return ' ' + parts[c.dataIndex].label + ': ' + dbkChart.fmt.money(c.parsed); }
                    }}}
                }
            });
            @endif
        });
    </script>
@endpush
