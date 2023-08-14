@php
    $money = fn($v) => number_format((float)$v, 2, ',', ' ');
    $pct = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', ' ') . ' %';
    $hasCompare = !empty($listing['compare']);
    $isAll = $side === 'all';
@endphp
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <title>@lang('databackd-reports-counterparties.title')</title>
    <style>
        @page { margin: 28px 30px 40px 30px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #28313b; margin: 0; }
        .head { width: 100%; border-bottom: 2px solid #1d82f5; padding-bottom: 8px; margin-bottom: 10px; }
        .head td { vertical-align: top; }
        .logo { max-height: 42px; max-width: 160px; }
        .company { font-size: 13px; font-weight: bold; }
        h1 { font-size: 15px; margin: 2px 0 4px 0; }
        .summary { color: #5b6773; font-size: 8.5px; line-height: 1.5; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.data th { background: #eef3f8; text-align: left; font-size: 8.5px; padding: 5px 6px; border-bottom: 1px solid #c8d3df; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #e8eef3; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #8a97a6; }
        .up { color: #1d8a4e; } .down { color: #d3302f; }
        tr.total td { font-weight: bold; border-top: 2px solid #28313b; border-bottom: 0; background: #f6f8fa; }
        .bar { background: #dbe9fb; height: 5px; }
        .bar i { display: block; background: #1d82f5; height: 5px; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 7.5px; color: #8a97a6; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td style="width: 60%">
            <div class="company">{{ $companyName }}</div>
            <h1>@lang('databackd-reports-counterparties.title') — @lang('databackd-reports-counterparties.sides.' . $side)</h1>
            <div class="summary">
                @foreach ($filters->summary() as $line)
                    {{ $line }}<br>
                @endforeach
            </div>
        </td>
        <td style="text-align: right">
            @if ($logo)
                <img src="{{ $logo }}" class="logo" alt="">
            @endif
        </td>
    </tr>
</table>

@if (empty($listing['rows']))
    <p class="muted">@lang('databackd-reports-common.no_data')</p>
@else
    <table class="data">
        <thead>
        <tr>
            <th style="width: 22px">#</th>
            <th>@lang('databackd-reports-counterparties.columns.counterparty')</th>
            <th class="num">@lang('databackd-reports-counterparties.columns.turnover') ({{ __('databackd-reports-common.currency') }})</th>
            @if ($isAll)
                <th class="num">@lang('databackd-reports-counterparties.columns.revenue')</th>
                <th class="num">@lang('databackd-reports-counterparties.columns.expense')</th>
            @endif
            <th class="num">@lang('databackd-reports-counterparties.columns.documents')</th>
            <th class="num">@lang('databackd-reports-counterparties.columns.avg_document')</th>
            <th style="width: 90px">@lang('databackd-reports-counterparties.columns.share')</th>
            @if ($hasCompare)
                <th class="num">@lang('databackd-reports-counterparties.columns.previous')</th>
                <th class="num">@lang('databackd-reports-counterparties.columns.change')</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @foreach ($listing['rows'] as $i => $r)
            <tr>
                <td class="muted">{{ $i + 1 }}</td>
                <td @class(['muted' => $r['is_none']])>{{ $r['label'] }}</td>
                <td class="num">{{ $money($r['total']) }}</td>
                @if ($isAll)
                    <td class="num">{{ $money($r['revenue']) }}</td>
                    <td class="num">{{ $money($r['expense']) }}</td>
                @endif
                <td class="num">{{ number_format($r['docs'], 0, ',', ' ') }}</td>
                <td class="num">{{ $money($r['avg']) }}</td>
                <td>
                    <div class="bar"><i style="width: {{ min(100, max(0, $r['share'])) }}%"></i></div>
                    {{ $pct($r['share']) }}
                </td>
                @if ($hasCompare)
                    <td class="num">{{ $money($r['previous'] ?? 0) }}</td>
                    <td class="num {{ ($r['change_pct'] ?? 0) > 0 ? 'up' : (($r['change_pct'] ?? 0) < 0 ? 'down' : '') }}">{{ $pct($r['change_pct'] ?? null) }}</td>
                @endif
            </tr>
        @endforeach
        <tr class="total">
            <td></td>
            <td>@lang('databackd-reports-common.total')</td>
            <td class="num">{{ $money($listing['totals']['total']) }}</td>
            @if ($isAll)
                <td class="num">{{ $money($listing['totals']['revenue']) }}</td>
                <td class="num">{{ $money($listing['totals']['expense']) }}</td>
            @endif
            <td class="num">{{ number_format($listing['totals']['docs'], 0, ',', ' ') }}</td>
            <td class="num">{{ $money($listing['totals']['avg']) }}</td>
            <td>100,0 %</td>
            @if ($hasCompare)
                <td class="num">{{ $money($listing['totals']['previous'] ?? 0) }}</td>
                <td class="num">{{ $pct($listing['totals']['change_pct'] ?? null) }}</td>
            @endif
        </tr>
        </tbody>
    </table>
    <p class="muted" style="margin-top: 8px">@lang('databackd-reports-counterparties.docs_hint')</p>
@endif

<div class="footer">{{ $companyName }} · @lang('databackd-reports-counterparties.title') · @lang('databackd-reports-counterparties.generated') {{ $generatedAt->format('d.m.Y H:i') }}</div>
</body>
</html>
