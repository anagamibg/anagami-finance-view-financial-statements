@extends('layouts.app')

@php
    $nbsp = "\u{00A0}";
    $money = fn($v) => number_format((float)$v, 2, ',', $nbsp);
    $pctFmt = fn($v) => $v === null ? '–' : number_format((float)$v, 1, ',', $nbsp) . '%';
    $hasCompare = $statement['compare'] !== null;
    $columns = $statement['columns'];
    $symbol = $currencySymbol ?? '€';
    $colspan = 1 + count($columns) + 2 + ($hasCompare ? 3 : 0);
    $signed = fn($v) => ($v > 0.004 ? '+' : ($v < -0.004 ? '−' : '')) . number_format(abs((float)$v), 2, ',', $nbsp);
    // "Financial entries" of the same period and legal entities as the statement; a group row opens all its accounts
    $entryParams = ['from' => $filters->from->toDateString(), 'to' => $filters->to->toDateString()];
    if ($filters->entityIds) {
        $entryParams['entity_id'] = implode(',', $filters->entityIds);
    }
    $entryLink = fn(array $row) => $entriesUrl && $row['account_ids']
        ? $entriesUrl . '?' . http_build_query($entryParams + ['account_id' => implode(',', $row['account_ids'])])
        : null;
    $hasExpandable = collect($statement['rows'])->contains(fn($r) => $r['expandable']);
@endphp

@section('content')
    @php
        $kpiIcon = ['net_sales' => 'bi-cash-stack', 'operating_profit' => 'bi-briefcase', 'net_profit' => 'bi-piggy-bank', 'margin' => 'bi-percent'];
        $kpiTone = ['net_sales' => 'revenue', 'operating_profit' => 'profit', 'net_profit' => 'profit', 'margin' => 'profit'];
        $alt = false;
    @endphp
    <div class="content-wrapper dbk-reports dbk-reports-pnl">

        <div class="pnl-print-header">
            <h4 class="mb-1">{{ company()->company_name }} – @lang('databackd-reports-pnl.print_header')</h4>
            <div class="f-12 text-dark-grey">
                @foreach ($filters->summary() as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($hasCompare)
                    <div>@lang('databackd-reports-pnl.compare_period', ['from' => $statement['compare']['from']->format('d.m.Y'), 'to' => $statement['compare']['to']->format('d.m.Y')])</div>
                @endif
                <div>@lang('databackd-reports-pnl.currency_note', ['currency' => $currency])</div>
            </div>
        </div>

        <x-dbk.page-header class="pnl-no-print" :title="__('databackd-reports-pnl.title')" :subtitle="__('databackd-reports-pnl.subtitle')">
            <x-slot name="actions">
                <div class="dbk-reports-actions">
                    @includeIf('databackd.reports.saved._save-button', ['type' => 'pnl', 'config' => ['filters' => $filters->toQuery()]])
                    <div class="btn-group" role="group">
                        <a href="{{ route('databackd.reports.pnl.excel', $query) }}" class="btn btn-secondary btn-sm"><i class="fa fa-file-excel mr-1"></i>@lang('databackd-reports-pnl.actions.excel')</a>
                        <a href="{{ route('databackd.reports.pnl.pdf', $query) }}" class="btn btn-secondary btn-sm"><i class="fa fa-file-pdf mr-1"></i>@lang('databackd-reports-pnl.actions.pdf')</a>
                        <button type="button" class="btn btn-secondary btn-sm" id="pnl-print"><i class="fa fa-print mr-1"></i>@lang('databackd-reports-pnl.actions.print')</button>
                    </div>
                </div>
            </x-slot>
        </x-dbk.page-header>

        @include('databackd.reports._filters', ['filters' => $filters, 'options' => ['period', 'periodicity', 'entities', 'dimensions', 'client', 'compare']])

        <!-- KPI -->
        <div class="dbk-kpi-grid pnl-kpis">
            @foreach ($statement['kpis'] as $kpi)
                @php
                    $kpiValue = $kpi['value'] === null ? '–' : ($kpi['kind'] === 'pct' ? $pctFmt($kpi['value']) : $money($kpi['value']) . $nbsp . $symbol);
                    $delta = $hasCompare ? ($kpi['kind'] === 'pct' ? $kpi['change_pp'] : $kpi['change_pct']) : null;
                @endphp
                <x-dbk.kpi
                    :label="__('databackd-reports-pnl.kpi.' . $kpi['key'])"
                    :value="$kpiValue"
                    :icon="$kpiIcon[$kpi['key']] ?? null"
                    :tone="$kpiTone[$kpi['key']] ?? null"
                    :change="$delta"
                    :change-unit="$kpi['kind'] === 'pct' ? __('databackd-reports-pnl.kpi.pp') : '%'"
                    good="up"
                    :hint="$hasCompare ? __('databackd-reports-pnl.kpi.vs_previous') : null"
                />
            @endforeach
        </div>

        @if ($statement['dimension_note'])
            <div class="alert alert-warning f-13 mb-3">
                <i class="fa fa-exclamation-triangle mr-1"></i>@lang('databackd-reports-pnl.notes.dimension_' . ($statement['dimension_note'] === 'none' ? 'none' : $statement['dimension_note']))
            </div>
        @endif

        @if (!$statement['has_data'])
            <x-dbk.empty class="dbk-card mb-3" icon="graph-up" :text="__('databackd-reports-pnl.empty')" />
        @endif

        <!-- P&L table -->
        <x-dbk.card class="mb-3" body-class="p-0">
            @if ($hasExpandable)
                <x-slot name="actions">
                    <div class="pnl-no-print dbk-reports-toggles">
                        <button type="button" class="btn btn-link btn-sm" id="pnl-expand-all"><i class="fa fa-plus-square mr-1"></i>@lang('databackd-reports-pnl.actions.expand_all')</button>
                        <button type="button" class="btn btn-link btn-sm" id="pnl-collapse-all"><i class="fa fa-minus-square mr-1"></i>@lang('databackd-reports-pnl.actions.collapse_all')</button>
                    </div>
                </x-slot>
            @endif
            <div class="dbk-table-wrap dbk-table-wrap--scroll is-flush pnl-scroll">
                <table class="dbk-table dbk-table--compact dbk-table--sticky dbk-table--sticky-first pnl-table" id="pnl-table">
                    <thead>
                    <tr>
                        <th class="pnl-name">@lang('databackd-reports-pnl.columns.item')</th>
                        @foreach ($columns as $column)
                            <th class="num">{{ $column['label'] }}</th>
                        @endforeach
                        <th class="num pnl-total">@lang('databackd-reports-pnl.columns.total')</th>
                        <th class="num">@lang('databackd-reports-pnl.columns.pct')</th>
                        @if ($hasCompare)
                            <th class="num" title="{{ $statement['compare']['from']->format('d.m.Y') }} – {{ $statement['compare']['to']->format('d.m.Y') }}">
                                @lang('databackd-reports-pnl.columns.previous')
                                <div class="pnl-th-sub">{{ $statement['compare']['from']->format('d.m.y') }} – {{ $statement['compare']['to']->format('d.m.y') }}</div>
                            </th>
                            <th class="num">@lang('databackd-reports-pnl.columns.change_abs')</th>
                            <th class="num">@lang('databackd-reports-pnl.columns.change')</th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($statement['rows'] as $row)
                        @if ($row['type'] === 'section')
                            @php $alt = false; @endphp
                            <tr class="pnl-section dbk-row-group"><td class="pnl-name">{{ $row['name'] }}</td><td colspan="{{ $colspan - 1 }}"></td></tr>
                            @continue
                        @endif

                        @php
                            $link = $entryLink($row);
                            // Zebra over the visible top-level rows only (account rows are hidden until their group is opened)
                            if (!$row['parent'] && $row['style'] === 'normal') {
                                $alt = !$alt;
                            }
                        @endphp
                        <tr class="pnl-row pnl-{{ $row['type'] }} pnl-{{ $row['style'] }} {{ $row['expandable'] ? 'pnl-has-children' : '' }} {{ $row['parent'] ? 'pnl-child' : '' }} {{ $row['style'] === 'result' ? 'dbk-row-total' : '' }} {{ !$row['parent'] && $row['style'] === 'normal' && !$alt ? 'is-alt' : '' }}"
                            data-id="{{ $row['id'] }}" @if ($row['parent']) data-parent="{{ $row['parent'] }}" @endif
                            @if ($row['expandable']) title="@lang('databackd-reports-pnl.actions.toggle')" @endif>
                            <td class="pnl-name">
                                @if ($row['expandable'])
                                    <i class="fa fa-chevron-right pnl-toggle f-11"></i>
                                @elseif ($row['type'] === 'group' && $hasExpandable)
                                    <span class="pnl-toggle-spacer"></span>
                                @endif
                                @if ($row['code'] !== '')
                                    <span class="pnl-code">{{ $row['code'] }}</span>
                                @endif
                                {{ $row['name'] }}
                            </td>
                            @foreach ($columns as $column)
                                @php $v = $row['values'][$column['key']] ?? 0; @endphp
                                <td class="num {{ $v < -0.004 ? 'neg' : ($v == 0 ? 'pnl-zero' : '') }}">{{ $money($v) }}</td>
                            @endforeach
                            <td class="num pnl-total {{ $row['total'] < -0.004 ? 'neg' : '' }}">
                                @if ($link)
                                    <a href="{{ $link }}" class="pnl-link" title="@lang('databackd-reports-pnl.actions.open_entries')">{{ $money($row['total']) }}</a>
                                @else
                                    {{ $money($row['total']) }}
                                @endif
                            </td>
                            <td class="num pnl-pct">{{ $pctFmt($row['pct']) }}</td>
                            @if ($hasCompare)
                                <td class="num {{ $row['prev'] < -0.004 ? 'neg' : '' }}">{{ $money($row['prev']) }}</td>
                                <td class="num {{ $row['change'] < -0.004 ? 'neg' : '' }}">{{ $signed($row['change']) }}</td>
                                <td class="num {{ ($row['change_pct'] ?? 0) < 0 ? 'neg' : '' }}">
                                    @if ($row['change_pct'] === null)
                                        –
                                    @else
                                        {{ $row['change_pct'] > 0 ? '+' : ($row['change_pct'] < 0 ? '−' : '') }}{{ number_format(abs($row['change_pct']), 1, ',', $nbsp) }}{{ $nbsp }}%
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-dbk.card>

        <!-- Notes -->
        <div class="dbk-reports-notes">
            <div class="dbk-reports-notes-title">@lang('databackd-reports-pnl.notes.title')</div>
            <ul>
                <li>@lang('databackd-reports-pnl.notes.pct_base')</li>
                <li>@lang('databackd-reports-pnl.notes.expenses_positive')</li>
                @if ($statement['tax']['mode'] === 'actual')
                    <li>@lang('databackd-reports-pnl.notes.tax_actual')</li>
                @else
                    <li>@lang('databackd-reports-pnl.notes.tax_estimated', ['rate' => (int)round($statement['tax']['rate'] * 100)])</li>
                @endif
                @if ($statement['extra_codes'])
                    <li>@lang('databackd-reports-pnl.notes.other_accounts', ['codes' => implode(', ', $statement['extra_codes'])])</li>
                @endif
                @if ($entriesUrl)
                    <li class="pnl-no-print">@lang('databackd-data-entries.url_filters.report_note')</li>
                @endif
            </ul>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var $table = $('#pnl-table');

            function setOpen($group, open) {
                $group.toggleClass('pnl-expanded', open);
                $table.find('tr[data-parent="' + $group.data('id') + '"]').toggleClass('pnl-open', open);
            }

            // Expand / collapse a group row (the account rows are already in the page)
            $table.on('click', 'tr.pnl-has-children', function (e) {
                if ($(e.target).closest('a').length) {
                    return;
                }

                setOpen($(this), !$(this).hasClass('pnl-expanded'));
            });

            $('#pnl-expand-all').on('click', function () {
                $table.find('tr.pnl-has-children').each(function () { setOpen($(this), true); });
            });

            $('#pnl-collapse-all').on('click', function () {
                $table.find('tr.pnl-has-children').each(function () { setOpen($(this), false); });
            });

            $('#pnl-print').on('click', function () {
                window.print();
            });
        })();
    </script>
@endpush
