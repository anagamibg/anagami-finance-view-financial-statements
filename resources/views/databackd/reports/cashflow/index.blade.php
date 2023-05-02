@extends('layouts.app')

@php
    $fmt = fn($v) => number_format((float)$v, 2, ',', ' ');
    $kpi = $statement['kpi'];
    $compare = $statement['compare'];
    $columns = $statement['columns'];
    $euro = __('databackd-reports-common.currency');
    $kpiCards = [
        'opening' => ['icon' => 'flag', 'good' => 'up', 'tone' => 'cash'],
        'operating' => ['icon' => 'briefcase', 'good' => 'up', 'tone' => 'revenue'],
        'net' => ['icon' => 'exchange-alt', 'good' => 'up', 'tone' => 'profit'],
        'closing' => ['icon' => 'piggy-bank', 'good' => 'up', 'tone' => 'cash'],
    ];
    $cashAccounts = $statement['notes']['cash_accounts'] ? implode(', ', $statement['notes']['cash_accounts']) : __('databackd-reports-cashflow.notes.no_cash_accounts');
    $strongKinds = ['opening', 'net', 'closing'];
@endphp

@section('content')
    <div class="content-wrapper dbk-reports dbk-reports-cashflow">

        <div class="cf-print-title">
            <h4 class="mb-1">@lang('databackd-reports-cashflow.pdf_title') · {{ company()->company_name }}</h4>
            <div class="f-12 text-dark-grey">{{ implode(' · ', $filters->summary()) }}</div>
        </div>

        <x-dbk.page-header class="cf-no-print" :title="__('databackd-reports-cashflow.title')" :subtitle="__('databackd-reports-cashflow.subtitle')">
            <x-slot name="actions">
                <div class="dbk-reports-actions">
                    @includeIf('databackd.reports.saved._save-button', ['type' => 'cashflow', 'config' => ['filters' => $filters->toQuery()]])
                    <div class="btn-group" role="group">
                        <a href="{{ $excelUrl }}" class="btn btn-secondary btn-sm"><i class="fa fa-file-excel mr-1"></i>@lang('databackd-reports-cashflow.actions.excel')</a>
                        <a href="{{ $pdfUrl }}" class="btn btn-secondary btn-sm"><i class="fa fa-file-pdf mr-1"></i>@lang('databackd-reports-cashflow.actions.pdf')</a>
                        <button type="button" class="btn btn-secondary btn-sm" id="cf-print"><i class="fa fa-print mr-1"></i>@lang('databackd-reports-cashflow.actions.print')</button>
                    </div>
                </div>
            </x-slot>
        </x-dbk.page-header>

        <div class="cf-no-print">
            @include('databackd.reports._filters', ['filters' => $filters, 'options' => ['period', 'periodicity', 'entities', 'dimensions', 'client', 'compare'], 'action' => route('databackd.reports.cashflow.index')])
        </div>

        @if ($statement['notes']['dimensions_ignored'])
            <div class="alert alert-info f-13 py-2"><i class="fa fa-info-circle mr-1"></i>@lang('databackd-reports-cashflow.notes.dimensions')</div>
        @endif
        @if ($statement['notes']['no_balances'])
            <div class="alert alert-warning f-13 py-2"><i class="fa fa-exclamation-triangle mr-1"></i>@lang('databackd-reports-cashflow.notes.no_balances')</div>
        @endif

        <!-- KPI start -->
        <div class="dbk-kpi-grid cf-kpi">
            @foreach ($kpiCards as $key => $card)
                <x-dbk.kpi
                    :label="__('databackd-reports-cashflow.kpi.' . $key)"
                    :value="$fmt($kpi[$key]) . ' ' . $euro"
                    :icon="$card['icon']"
                    :tone="$card['tone']"
                    :change="$compare ? ($compare['change'][$key] ?? null) : null"
                    :good="$card['good']"
                    :hint="$compare ? '· ' . $fmt($compare['kpi'][$key]) . ' ' . $euro : null"
                />
            @endforeach
        </div>
        @if ($compare)
            <p class="dbk-reports-hint mt-0 mb-3 cf-no-print">@lang('databackd-reports-cashflow.compare_period', ['from' => $compare['period']['from']->format('d.m.Y'), 'to' => $compare['period']['to']->format('d.m.Y')])</p>
        @endif
        <!-- KPI end -->

        @if (!$statement['has_data'])
            <x-dbk.empty class="dbk-card mb-3" icon="database" :text="__('databackd-reports-common.no_data')" />
        @else
            <!-- Chart start: flows and the balance are on different scales → two charts, one value axis each -->
            <x-dbk.card class="mb-3 cf-chart-card" :title="__('databackd-reports-cashflow.chart.title')">
                <div class="dbk-grid">
                    <div class="dbk-span-8">
                        <x-dbk.chart id="cf-chart" :label="__('databackd-reports-cashflow.chart.title')" />
                    </div>
                    <div class="dbk-span-4">
                        <p class="dbk-reports-chart-label">@lang('databackd-reports-cashflow.chart.closing')</p>
                        <x-dbk.chart id="cf-chart-balance" size="sm" :label="__('databackd-reports-cashflow.chart.closing')" />
                    </div>
                </div>
            </x-dbk.card>
            <!-- Chart end -->

            <!-- Table start -->
            <x-dbk.card class="mb-3" body-class="p-0">
                <div class="dbk-table-wrap dbk-table-wrap--scroll is-flush cf-table-wrap">
                    <table class="dbk-table dbk-table--compact dbk-table--sticky dbk-table--sticky-first cf-table" id="cf-table">
                        <thead>
                        <tr>
                            <th class="cf-label">@lang('databackd-reports-cashflow.columns.label')</th>
                            @foreach ($columns as $col)
                                <th class="num">{{ $col['label'] }}</th>
                            @endforeach
                            <th class="num cf-total">@lang('databackd-reports-cashflow.columns.total')</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($statement['rows'] as $row)
                            @php
                                $strong = in_array($row['kind'], $strongKinds, true);
                                $expandable = !empty($row['children']);
                            @endphp
                            <tr class="{{ $strong ? 'cf-main cf-strong dbk-row-total' : ($row['kind'] === 'other' ? 'cf-main cf-other' : 'cf-main dbk-row-group') }}">
                                <td class="cf-label">
                                    @if ($expandable)
                                        <button type="button" class="btn btn-link p-0 border-0 align-baseline cf-toggle open" data-group="{{ $row['kind'] }}" aria-expanded="true" title="@lang('databackd-reports-cashflow.hints.expand')" aria-label="@lang('databackd-reports-cashflow.hints.expand')"><i class="fa fa-chevron-right f-11"></i></button>
                                    @endif
                                    {{ $row['label'] }}
                                    <i class="fa fa-question-circle cf-hint cf-no-print" data-toggle="tooltip" data-placement="right" title="{{ __('databackd-reports-cashflow.hints.' . $row['kind'], ['accounts' => $cashAccounts]) }}"></i>
                                </td>
                                @foreach ($columns as $col)
                                    @php $v = $row['values'][$col['key']] ?? 0; @endphp
                                    <td class="num {{ $v < 0 ? 'neg' : '' }}">{{ $fmt($v) }}</td>
                                @endforeach
                                <td class="num cf-total {{ $row['total'] < 0 ? 'neg' : '' }}">{{ $fmt($row['total']) }}</td>
                            </tr>
                            @foreach ($row['children'] as $child)
                                <tr class="cf-child {{ $loop->even ? 'is-alt' : '' }}" data-parent="{{ $row['kind'] }}">
                                    <td class="cf-label">{{ $child['code'] !== '' ? $child['code'] . ' · ' : '' }}{{ $child['label'] }}</td>
                                    @foreach ($columns as $col)
                                        @php $cv = $child['values'][$col['key']] ?? 0; @endphp
                                        <td class="num {{ $cv < 0 ? 'neg' : '' }}">{{ $fmt($cv) }}</td>
                                    @endforeach
                                    <td class="num cf-total {{ $child['total'] < 0 ? 'neg' : '' }}">{{ $fmt($child['total']) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-dbk.card>
            <!-- Table end -->

            <p class="dbk-reports-hint mb-0">@lang('databackd-reports-cashflow.notes.basis', ['accounts' => $cashAccounts])</p>
        @endif

    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('[data-toggle="tooltip"]').tooltip({container: 'body'});

            $('#cf-print').on('click', function () {
                window.print();
            });

            // Expand / collapse the account breakdown of receipts and payments
            $('.cf-toggle').on('click', function () {
                var $t = $(this).toggleClass('open');
                $t.attr('aria-expanded', $t.hasClass('open') ? 'true' : 'false');
                $('tr.cf-child[data-parent="' + $t.data('group') + '"]').toggle($t.hasClass('open'));
            });

            @if ($statement['has_data'])
            var chart = @json($statement['chart']);

            // net cash flow of every activity per period (categorical colours in the fixed order)
            dbkChart.bar('cf-chart', {
                labels: chart.labels,
                datasets: [
                    {label: @json(__('databackd-reports-cashflow.chart.operating')), data: chart.operating},
                    {label: @json(__('databackd-reports-cashflow.chart.investing')), data: chart.investing},
                    {label: @json(__('databackd-reports-cashflow.chart.financing')), data: chart.financing}
                ]
            });

            dbkChart.line('cf-chart-balance', {
                labels: chart.labels,
                datasets: [{label: @json(__('databackd-reports-cashflow.chart.closing')), data: chart.closing, role: 'cash', area: true}],
                legend: false
            });
            @endif
        });
    </script>
@endpush
