@php
    $t = $report['totals'];
    $compare = $report['compare'];
    $hasCompare = $compare !== null;
    $nb = "\u{00A0}";
    $num = fn($v, $d = 2) => number_format((float)$v, $d, ',', $nb);
    $money = fn($v) => $v === null ? '–' : ((float)$v < -0.004 ? '-' : '') . $num(abs((float)$v));
    $qty = fn($v) => $num($v, abs((float)$v - round((float)$v)) < 0.005 ? 0 : 2);
    $pct = fn($v) => $v === null ? '–' : $num($v, 1) . $nb . '%';
    $signed = fn($v) => $v === null ? '–' : ($v > 0 ? '+' : ($v < 0 ? '-' : '')) . $num(abs($v), 1) . $nb . '%';
    $neg = fn($v) => $v !== null && (float)$v < -0.004 ? 'neg' : '';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>@lang('databackd-gaps-margins.export.pdf_title')</title>
    <style>
        @page { margin: 26px 24px 30px 24px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 7.6pt; color: #1d2b3a; margin: 0; }
        .head { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .head td { vertical-align: top; padding: 0; }
        .logo { height: 36px; width: 36px; }
        .company { font-size: 12pt; font-weight: bold; }
        .title { font-size: 10pt; color: #4a5a6a; margin-top: 2px; }
        .summary { font-size: 7pt; color: #5b6b7b; margin-top: 3px; line-height: 1.5; }
        .right { text-align: right; }
        h3 { font-size: 9pt; margin: 10px 0 4px 0; }
        table.data { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.data th { background: #2a78d6; color: #fff; font-weight: bold; padding: 4px 3px; text-align: right; border: 0; }
        table.data th.name { text-align: left; }
        table.data td { padding: 2.5px 3px; border-bottom: 0.5px solid #e4e9ee; text-align: right; }
        table.data td.name { text-align: left; }
        table.data tr.total td { background: #e8eef3; font-weight: bold; border-top: 0.8px solid #8fb7e8; }
        .neg { color: #d0021b; }
        .muted { color: #8a97a4; }
        .warn { color: #b26a00; }
        .notes { margin-top: 8px; font-size: 6.6pt; color: #6b7a89; line-height: 1.5; }
        .footer { position: fixed; bottom: -16px; left: 0; right: 0; font-size: 6.5pt; color: #8a97a4; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td style="width: 70%">
            <div class="company">{{ $company->company_name ?? '' }}</div>
            <div class="title">@lang('databackd-gaps-margins.export.pdf_title')</div>
            <div class="summary">
                @foreach ($filters->summary() as $line)
                    @if (!str_starts_with($line, __('databackd-reports-common.filters.periodicity')))
                        {{ $line }}<br>
                    @endif
                @endforeach
                @if ($report['filters']['category'])
                    @lang('databackd-gaps-margins.filters.category'): {{ $report['filters']['category'] }}<br>
                @endif
                @if ($hasCompare)
                    @lang('databackd-gaps-margins.compare_period', ['from' => $compare['from']->format('d.m.Y'), 'to' => $compare['to']->format('d.m.Y')])<br>
                @endif
                @lang('databackd-gaps-margins.currency_note', ['currency' => $report['currency']])
            </div>
        </td>
        <td class="right" style="width: 30%">
            @if ($logo)
                <img src="{{ $logo }}" class="logo" alt="">
            @endif
        </td>
    </tr>
</table>

<h3>@lang('databackd-gaps-margins.groups.title')</h3>
<table class="data">
    <thead>
    <tr>
        <th class="name" style="width: 26%">@lang('databackd-gaps-margins.table.columns.group')</th>
        <th>@lang('databackd-gaps-margins.table.columns.revenue')</th>
        <th>@lang('databackd-gaps-margins.table.columns.cost')</th>
        <th>@lang('databackd-gaps-margins.table.columns.margin')</th>
        <th>@lang('databackd-gaps-margins.table.columns.margin_pct')</th>
        <th>@lang('databackd-gaps-margins.table.columns.share')</th>
        @if ($hasCompare)
            <th>@lang('databackd-gaps-margins.table.columns.prev_margin')</th>
            <th>@lang('databackd-gaps-margins.table.columns.change')</th>
        @endif
    </tr>
    </thead>
    <tbody>
    @foreach ($report['groups'] as $g)
        <tr>
            <td class="name">{{ $g['name'] }}@if ($g['missing_count'] > 0) <span class="warn">*</span>@endif</td>
            <td>{{ $money($g['revenue']) }}</td>
            <td>{{ $money($g['cost']) }}</td>
            <td class="{{ $neg($g['margin']) }}">{{ $money($g['margin']) }}</td>
            <td class="{{ $neg($g['margin']) }}">{{ $pct($g['margin_pct']) }}</td>
            <td>{{ $pct($g['share']) }}</td>
            @if ($hasCompare)
                <td>{{ $money($g['prev_margin'] ?? null) }}</td>
                <td class="{{ $neg($g['margin_change_pct'] ?? null) }}">{{ $signed($g['margin_change_pct'] ?? null) }}</td>
            @endif
        </tr>
    @endforeach
    <tr class="total">
        <td class="name">@lang('databackd-gaps-margins.table.total')</td>
        <td>{{ $money($t['revenue']) }}</td>
        <td>{{ $t['costed_count'] ? $money($t['cost']) : '–' }}</td>
        <td class="{{ $neg($t['margin']) }}">{{ $money($t['margin']) }}</td>
        <td class="{{ $neg($t['margin']) }}">{{ $pct($t['margin_pct']) }}</td>
        <td>100,0{{ $nb }}%</td>
        @if ($hasCompare)
            <td>{{ $money($compare['totals']['margin']) }}</td>
            <td class="{{ $neg($compare['changes']['margin_pct'] ?? null) }}">{{ $signed($compare['changes']['margin_pct'] ?? null) }}</td>
        @endif
    </tr>
    </tbody>
</table>

<h3>@lang('databackd-gaps-margins.table.title')</h3>
<table class="data">
    <thead>
    <tr>
        <th class="name" style="width: 28%">@lang('databackd-gaps-margins.table.columns.product')</th>
        <th class="name" style="width: 14%">@lang('databackd-gaps-margins.table.columns.group')</th>
        <th>@lang('databackd-gaps-margins.table.columns.quantity')</th>
        <th>@lang('databackd-gaps-margins.table.columns.revenue')</th>
        <th>@lang('databackd-gaps-margins.table.columns.cost')</th>
        <th>@lang('databackd-gaps-margins.table.columns.margin')</th>
        <th>@lang('databackd-gaps-margins.table.columns.margin_pct')</th>
        <th>@lang('databackd-gaps-margins.table.columns.share')</th>
        @if ($hasCompare)
            <th>@lang('databackd-gaps-margins.table.columns.change')</th>
        @endif
    </tr>
    </thead>
    <tbody>
    @foreach ($report['rows'] as $r)
        <tr>
            <td class="name">{{ $r['product_id'] ? $r['name'] : __('databackd-gaps-margins.table.unlinked') }}@if ($r['sku']) <span class="muted">({{ $r['sku'] }})</span>@endif</td>
            <td class="name">{{ $r['group'] }}</td>
            <td>{{ $qty($r['quantity']) }}</td>
            <td>{{ $money($r['revenue']) }}</td>
            @if ($r['has_cost'])
                <td>{{ $money($r['cost']) }}</td>
                <td class="{{ $neg($r['margin']) }}">{{ $money($r['margin']) }}</td>
                <td class="{{ $neg($r['margin']) }}">{{ $pct($r['margin_pct']) }}</td>
            @else
                <td class="warn" colspan="3" style="text-align: center">{{ __('databackd-gaps-margins.table.no_cost') }}</td>
            @endif
            <td>{{ $pct($r['share']) }}</td>
            @if ($hasCompare)
                <td class="{{ $neg($r['margin_change_pct'] ?? null) }}">{{ $signed($r['margin_change_pct'] ?? null) }}</td>
            @endif
        </tr>
    @endforeach
    <tr class="total">
        <td class="name" colspan="2">@lang('databackd-gaps-margins.table.total')</td>
        <td>{{ $qty($t['quantity']) }}</td>
        <td>{{ $money($t['revenue']) }}</td>
        <td>{{ $t['costed_count'] ? $money($t['cost']) : '–' }}</td>
        <td class="{{ $neg($t['margin']) }}">{{ $money($t['margin']) }}</td>
        <td class="{{ $neg($t['margin']) }}">{{ $pct($t['margin_pct']) }}</td>
        <td>100,0{{ $nb }}%</td>
        @if ($hasCompare)
            <td class="{{ $neg($compare['changes']['margin_pct'] ?? null) }}">{{ $signed($compare['changes']['margin_pct'] ?? null) }}</td>
        @endif
    </tr>
    </tbody>
</table>

<div class="notes">
    @lang('databackd-gaps-margins.notes.revenue')<br>
    @lang('databackd-gaps-margins.notes.cost')<br>
    @lang('databackd-gaps-margins.notes.group')
</div>
<div class="footer">{{ $company->company_name ?? '' }} · @lang('databackd-gaps-margins.notes.data_as_of') {{ $generatedAt }}</div>
</body>
</html>
