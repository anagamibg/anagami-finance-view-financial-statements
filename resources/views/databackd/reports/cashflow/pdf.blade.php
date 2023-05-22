@php
    $fmt = fn($v) => number_format((float)$v, 2, ',', ' ');
    $kpi = $statement['kpi'];
    $blockCount = count($blocks);
    $labelWidth = 230;
    $cashAccounts = $statement['notes']['cash_accounts'] ? implode(', ', $statement['notes']['cash_accounts']) : __('databackd-reports-cashflow.notes.no_cash_accounts');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>@lang('databackd-reports-cashflow.pdf_title')</title>
    <style>
        @page { margin: 28px 30px 40px 30px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 8px; color: #28313c; margin: 0; }
        h1 { font-size: 15px; margin: 0 0 3px 0; }
        .company { font-size: 10px; color: #616e80; }
        .summary { font-size: 8px; color: #616e80; margin-top: 4px; }
        .header { width: 100%; border-bottom: 2px solid #1d82f5; padding-bottom: 8px; margin-bottom: 10px; }
        .header td { vertical-align: middle; }
        .logo { max-height: 38px; max-width: 150px; }
        .kpi { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 12px -6px; }
        .kpi td { border: 1px solid #e8eef3; background: #f7fafc; padding: 6px 8px; width: 25%; }
        .kpi .label { font-size: 7px; color: #616e80; text-transform: uppercase; }
        .kpi .value { font-size: 12px; font-weight: bold; margin-top: 2px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #1d82f5; color: #fff; padding: 4px 4px; font-size: 7.5px; text-align: right; }
        table.data th.l { text-align: left; }
        table.data td { padding: 2.5px 4px; text-align: right; border-bottom: 1px solid #e8eef3; white-space: nowrap; }
        table.data td.l { text-align: left; white-space: normal; }
        table.data td.total, table.data th.total { border-left: 1px solid #c4ccd6; font-weight: bold; }
        tr.main td { font-weight: bold; background: #f2f4f7; }
        tr.child td { color: #616e80; }
        tr.child td.l { padding-left: 12px; }
        .neg { color: #d9372b; }
        .notes { margin-top: 10px; font-size: 7.5px; color: #616e80; line-height: 1.5; }
        .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 7px; color: #8a94a3; border-top: 1px solid #e8eef3; padding-top: 4px; }
        .footer .right { float: right; }
        .pagenum:before { content: counter(page); }
        .page-break { page-break-after: always; }
        .block-title { font-size: 8px; color: #616e80; margin: 0 0 4px 0; }
    </style>
</head>
<body>
<div class="footer">
    <span class="right">@lang('databackd-reports-cashflow.page') <span class="pagenum"></span></span>
    {{ $companyName }} · @lang('databackd-reports-cashflow.generated', ['date' => $generatedAt])
</div>

<table class="header">
    <tr>
        @if ($logo)
            <td style="width: 160px"><img src="{{ $logo }}" class="logo" alt=""></td>
        @endif
        <td>
            <h1>@lang('databackd-reports-cashflow.pdf_title')</h1>
            <div class="company">{{ $companyName }}</div>
            <div class="summary">{!! implode(' &nbsp;·&nbsp; ', array_map('e', $filters->summary())) !!}</div>
        </td>
    </tr>
</table>

<table class="kpi">
    <tr>
        @foreach (['opening', 'operating', 'net', 'closing'] as $k)
            <td>
                <div class="label">@lang('databackd-reports-cashflow.kpi.' . $k)</div>
                <div class="value {{ $kpi[$k] < -0.004 ? 'neg' : '' }}">{{ $fmt($kpi[$k]) }} @lang('databackd-reports-common.currency')</div>
            </td>
        @endforeach
    </tr>
</table>

@foreach ($blocks as $bi => $cols)
    @php $isLast = $bi === $blockCount - 1; @endphp
    @if ($blockCount > 1)
        <p class="block-title">{{ $cols[0]['label'] }} – {{ $cols[count($cols) - 1]['label'] }} ({{ $bi + 1 }}/{{ $blockCount }})</p>
    @endif

    <table class="data">
        <thead>
        <tr>
            <th class="l" style="width: {{ $labelWidth }}px">@lang('databackd-reports-cashflow.columns.label')</th>
            @foreach ($cols as $c)
                <th>{{ $c['label'] }}</th>
            @endforeach
            @if ($isLast)
                <th class="total" style="width: 66px">@lang('databackd-reports-cashflow.columns.total')</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @foreach ($statement['rows'] as $row)
            @php $main = in_array($row['kind'], ['opening', 'net', 'closing'], true); @endphp
            <tr class="{{ $main ? 'main' : '' }}">
                <td class="l" @if (!$main && $row['kind'] !== 'other') style="font-weight: bold" @endif>{{ $row['label'] }}</td>
                @foreach ($cols as $c)
                    <td class="{{ ($row['values'][$c['key']] ?? 0) < 0 ? 'neg' : '' }}">{{ $fmt($row['values'][$c['key']] ?? 0) }}</td>
                @endforeach
                @if ($isLast)
                    <td class="total {{ $row['total'] < 0 ? 'neg' : '' }}">{{ $fmt($row['total']) }}</td>
                @endif
            </tr>
            @foreach ($row['children'] as $child)
                <tr class="child">
                    <td class="l">{{ $child['code'] !== '' ? $child['code'] . ' · ' : '' }}{{ $child['label'] }}</td>
                    @foreach ($cols as $c)
                        <td class="{{ ($child['values'][$c['key']] ?? 0) < -0.004 ? 'neg' : '' }}">{{ $fmt($child['values'][$c['key']] ?? 0) }}</td>
                    @endforeach
                    @if ($isLast)
                        <td class="total {{ $child['total'] < -0.004 ? 'neg' : '' }}">{{ $fmt($child['total']) }}</td>
                    @endif
                </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>

    @if ($isLast)
        <div class="notes">
            <div>{{ __('databackd-reports-cashflow.notes.basis', ['accounts' => $cashAccounts]) }}</div>
            <div><b>{{ __('databackd-reports-cashflow.rows.other') }}:</b> {{ __('databackd-reports-cashflow.hints.other') }}</div>
            @if ($statement['notes']['dimensions_ignored'])
                <div>{{ __('databackd-reports-cashflow.notes.dimensions') }}</div>
            @endif
            @if ($statement['notes']['no_balances'])
                <div>{{ __('databackd-reports-cashflow.notes.no_balances') }}</div>
            @endif
        </div>
    @else
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
