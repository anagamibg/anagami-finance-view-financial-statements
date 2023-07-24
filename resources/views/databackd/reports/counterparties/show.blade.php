@extends('layouts.app')

@php
    $money = fn($v) => number_format((float)$v, 2, ',', ' ');
    $pct = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', ' ') . ' %';
    $kpi = $detail['kpi'];
    $isAll = $side === 'all';
    $backUrl = route('databackd.reports.counterparties.index', $query);
    $detailQuery = array_merge($query, ['name' => $detail['name'] === '' ? \App\Exports\Databackd\Reports\CounterpartyData::NONE_KEY : $detail['name']]);
    $hasData = $kpi['total'] > 0 || count($detail['latest']);
    $cur = __('databackd-reports-common.currency');
    $sideRole = ['revenue' => 'revenue', 'expense' => 'expense'][$side] ?? 'profit';
@endphp

@section('content')
    <div class="content-wrapper dbk-reports cp-report">

        <div class="d-none d-print-block mb-3">
            <h4 class="mb-1">{{ $detail['label'] }} — @lang('databackd-reports-counterparties.sides.' . $side)</h4>
            <div class="f-12 text-dark-grey">
                @foreach ($filters->summary() as $line)
                    {{ $line }}<br>
                @endforeach
            </div>
        </div>

        <x-dbk.page-header class="cp-noprint" :title="$detail['label']">
            <div class="cp-show-meta">
                <a href="{{ $backUrl }}" class="cp-back"><i class="fa fa-arrow-left mr-1"></i>@lang('databackd-reports-counterparties.back')</a>
                <span class="dbk-badge is-info">@lang('databackd-reports-counterparties.sides.' . $side)</span>
            </div>
            <x-slot name="actions">
                <div class="dbk-reports-actions">
                    <button type="button" class="btn btn-secondary btn-sm" id="cp-print"><i class="fa fa-print mr-1"></i>@lang('databackd-reports-counterparties.print')</button>
                </div>
            </x-slot>
        </x-dbk.page-header>

        <div class="cp-noprint">
            @include('databackd.reports._filters', ['filters' => $filters, 'options' => ['period', 'periodicity', 'entities', 'dimensions', 'client', 'compare'], 'action' => route('databackd.reports.counterparties.show'), 'hidden' => ['side' => $side, 'name' => $detail['name'] === '' ? \App\Exports\Databackd\Reports\CounterpartyData::NONE_KEY : $detail['name']]])
        </div>

        @if (!$hasData)
            <x-dbk.empty class="dbk-card mb-3" icon="people" :text="__('databackd-reports-common.no_data')" />
        @else
            @php
                $cards = [
                    ['turnover', $money($kpi['total']) . ' ' . $cur, 'bi-cash-stack'],
                    ['documents', number_format($kpi['docs'], 0, ',', ' '), 'bi-file-earmark-text'],
                    ['avg_document', $money($kpi['avg']) . ' ' . $cur, 'bi-calculator'],
                    ['share', $pct($kpi['share']), 'bi-pie-chart'],
                    ['last_date', $kpi['last_date'] ? \Carbon\Carbon::parse($kpi['last_date'])->format('d.m.Y') : '—', 'bi-calendar-event'],
                ];
            @endphp
            <div class="dbk-kpi-grid dbk-kpi-grid--5">
                @foreach ($cards as [$key, $value, $icon])
                    <x-dbk.kpi :label="__('databackd-reports-counterparties.columns.' . $key)" :value="$value" :icon="$icon"
                        :change="$key === 'turnover' && isset($kpi['change_pct']) ? $kpi['change_pct'] : null"
                        :good="$side === 'expense' ? 'down' : 'up'"
                        :hint="$key === 'turnover' && isset($kpi['change_pct']) ? __('databackd-reports-counterparties.vs_previous') : null" />
                @endforeach
            </div>

            <div class="dbk-grid mb-3">
                <x-dbk.card class="dbk-reports-span-7 cp-noprint-chart" :title="__('databackd-reports-counterparties.chart_periods')">
                    <x-dbk.chart id="cp-periods" :label="__('databackd-reports-counterparties.chart_periods')" />
                </x-dbk.card>
                <x-dbk.card class="dbk-reports-span-5" body-class="p-0" header-border :title="__('databackd-reports-counterparties.by_period')">
                    <div class="dbk-table-wrap is-flush cp-periods-wrap">
                        <table class="dbk-table dbk-table--compact dbk-table--sticky">
                            <thead>
                            <tr>
                                <th>@lang('databackd-reports-counterparties.columns.period')</th>
                                <th class="num">@lang('databackd-reports-counterparties.columns.turnover')</th>
                                <th class="num">@lang('databackd-reports-counterparties.columns.documents')</th>
                                <th class="num">@lang('databackd-reports-counterparties.columns.change')</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($detail['periods'] as $p)
                                <tr>
                                    <td>{{ $p['label'] }}</td>
                                    <td @class(['num', 'neg' => $p['total'] < 0])>{{ $money($p['total']) }}</td>
                                    <td class="num">{{ $p['docs'] }}</td>
                                    <td class="num {{ ($p['change_pct'] ?? 0) > 0 ? 'pos' : (($p['change_pct'] ?? 0) < 0 ? 'neg' : '') }}">{{ $pct($p['change_pct']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot>
                            <tr class="dbk-row-total">
                                <td>@lang('databackd-reports-common.total')</td>
                                <td class="num">{{ $money($kpi['total']) }}</td>
                                <td class="num">{{ $kpi['docs'] }}</td>
                                <td></td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </x-dbk.card>
            </div>

            {{-- Breakdowns --}}
            <div class="dbk-grid mb-3">
                @foreach ($detail['breakdowns'] as $key => $items)
                    @if ($items)
                        <x-dbk.card class="dbk-span-6" body-class="p-0" header-border :title="__('databackd-reports-counterparties.breakdowns.' . $key)">
                            <div class="dbk-table-wrap is-flush">
                                <table class="dbk-table dbk-table--compact">
                                    <tbody>
                                    @foreach ($items as $item)
                                        <tr>
                                            <td>{{ $item['label'] }}</td>
                                            <td @class(['num', 'neg' => $item['total'] < 0])>{{ $money($item['total']) }}</td>
                                            <td style="width: 150px">
                                                <div class="cp-share">
                                                    <div class="cp-bar"><i style="width: {{ max(0, min(100, $item['share'])) }}%"></i></div>
                                                    <span class="dbk-num">{{ $pct($item['share']) }}</span>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </x-dbk.card>
                    @endif
                @endforeach
            </div>

            {{-- Latest documents --}}
            <x-dbk.card class="mb-3" body-class="p-0" header-border :title="__('databackd-reports-counterparties.latest', ['count' => count($detail['latest'])])">
                <div class="dbk-table-wrap is-flush">
                    <table class="dbk-table dbk-table--striped">
                        <thead>
                        <tr>
                            <th>@lang('databackd-reports-counterparties.columns.date')</th>
                            <th>@lang('databackd-reports-counterparties.columns.document_no')</th>
                            <th>@lang('databackd-reports-counterparties.columns.account')</th>
                            <th>@lang('databackd-reports-counterparties.columns.entity')</th>
                            <th class="num">@lang('databackd-reports-counterparties.columns.amount')</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($detail['latest'] as $row)
                            <tr>
                                <td class="dbk-num text-nowrap">{{ $row['date']->format('d.m.Y') }}</td>
                                <td>{{ $row['document_no'] ?: '—' }}</td>
                                <td>{{ $row['account'] }}
                                    @if ($isAll)
                                        <span class="dbk-badge">@lang('databackd-reports-common.account_types.' . $row['type'])</span>
                                    @endif
                                </td>
                                <td>{{ $row['entity'] ?: '—' }}</td>
                                <td @class(['num', 'neg' => $row['amount'] < 0])>{{ $money($row['amount']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-dbk.card>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('#cp-print').on('click', function () { window.print(); });

            var periods = @json($detail['periods']);

            if (document.getElementById('cp-periods')) {
                dbkChart.bar('cp-periods', {
                    labels: periods.map(function (p) { return p.label; }),
                    datasets: [{label: @json(__('databackd-reports-counterparties.columns.turnover')), data: periods.map(function (p) { return p.total; }), role: @json($sideRole)}],
                    legend: false
                });
            }
        });
    </script>
@endpush
