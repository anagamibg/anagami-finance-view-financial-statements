@php
    $cols = count($statement['columns']);
    $perBlock = \App\Exports\Databackd\Reports\PnlPdf::MAX_COLUMNS;
    $fontSize = $cols > 9 ? 6 : ($cols > 5 ? 7.4 : 8.6);
    $hasCompare = $statement['compare'] !== null;
    $money = function ($v) {
        $text = number_format(abs((float)$v), 2, ',', "\u{00A0}");
        return ((float)$v < -0.004 ? '-' : '') . $text;
    };
    $pct = fn($v) => $v === null ? '–' : number_format((float)$v, 1, ',', "\u{00A0}") . '%';
    $blockCount = count($blocks);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>@lang('databackd-reports-pnl.export.pdf_title')</title>
    <style>
        @page { margin: 26px 24px 30px 24px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: {{ $fontSize }}pt; color: #1d2b3a; margin: 0; }
        .head { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .head td { vertical-align: top; padding: 0; }
        .logo { height: 36px; width: 36px; }
        .company { font-size: 12pt; font-weight: bold; }
        .title { font-size: 10pt; color: #4a5a6a; margin-top: 2px; }
        .summary { font-size: 7pt; color: #5b6b7b; margin-top: 3px; line-height: 1.5; }
        .right { text-align: right; }
        table.pnl { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.pnl th { background: #1d82f5; color: #fff; font-weight: bold; padding: 4px 3px; text-align: right; border: 0; }
        table.pnl th.name { text-align: left; }
        table.pnl td { padding: 2.5px 3px; border-bottom: 0.5px solid #e4e9ee; text-align: right; }
        table.pnl td.name { text-align: left; }
        table.pnl td.code { color: #7a8794; text-align: left; }
        tr.section td { background: #e8eef3; font-weight: bold; text-align: left; text-transform: uppercase; font-size: 0.9em; }
        tr.subtotal td { background: #f2f4f7; font-weight: bold; }
        tr.result td { background: #ddebfb; font-weight: bold; border-top: 0.8px solid #8fb7e8; }
        tr.account td { color: #55626f; }
        tr.account td.name { padding-left: 16px; }
        .neg { color: #d0021b; }
        .notes { margin-top: 8px; font-size: 6.6pt; color: #6b7a89; line-height: 1.5; }
        .block-label { font-size: 7pt; color: #7a8794; margin-bottom: 4px; }
        .footer { position: fixed; bottom: -16px; left: 0; right: 0; font-size: 6.5pt; color: #8a97a4; }
    </style>
</head>
<body>
@foreach ($blocks as $blockIndex => $block)
    @php $isLast = $blockIndex === $blockCount - 1; @endphp
    <div @if (!$isLast) style="page-break-after: always" @endif>
        <table class="head">
            <tr>
                <td style="width: 60%">
                    <div class="company">{{ $company->company_name ?? '' }}</div>
                    <div class="title">@lang('databackd-reports-pnl.export.pdf_title')</div>
                    <div class="summary">
                        @foreach ($filters->summary() as $line)
                            {{ $line }}<br>
                        @endforeach
                        @if ($hasCompare)
                            @lang('databackd-reports-pnl.compare_period', ['from' => $statement['compare']['from']->format('d.m.Y'), 'to' => $statement['compare']['to']->format('d.m.Y')])<br>
                        @endif
                        @lang('databackd-reports-pnl.currency_note', ['currency' => $statement['currency']])
                    </div>
                </td>
                <td class="right" style="width: 40%">
                    @if ($logo)
                        <img src="{{ $logo }}" class="logo" alt="">
                    @endif
                    @if ($blockCount > 1)
                        <div class="block-label">@lang('databackd-reports-pnl.export.page', ['n' => $blockIndex + 1, 'total' => $blockCount])</div>
                    @endif
                </td>
            </tr>
        </table>

        @php
            $extra = $isLast ? ($hasCompare ? 5 : 2) : 0;
            $nameWidth = $cols > 9 ? 17 : 28;
        @endphp
        <table class="pnl">
            @php
                $units = count($block) + ($isLast ? ($hasCompare ? 1.35 + 0.9 + 1.3 + 1.2 + 0.9 : 1.35 + 0.9) : 0);
                $unit = (100 - $nameWidth) / $units;
                $w = fn($k) => 'width: ' . round($unit * $k, 3) . '%';
            @endphp
            <thead>
            <tr>
                <th class="name" style="width: {{ $nameWidth }}%">@lang('databackd-reports-pnl.columns.item')</th>
                @foreach ($block as $column)
                    <th style="{{ $w(1) }}">{{ $column['label'] }}</th>
                @endforeach
                @if ($isLast)
                    <th style="{{ $w(1.35) }}">@lang('databackd-reports-pnl.columns.total')</th>
                    <th style="{{ $w(0.9) }}">@lang('databackd-reports-pnl.columns.pct')</th>
                    @if ($hasCompare)
                        <th style="{{ $w(1.3) }}">@lang('databackd-reports-pnl.columns.previous')</th>
                        <th style="{{ $w(1.2) }}">@lang('databackd-reports-pnl.columns.change_abs')</th>
                        <th style="{{ $w(0.9) }}">@lang('databackd-reports-pnl.columns.change')</th>
                    @endif
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($statement['rows'] as $row)
                @if ($row['type'] === 'section')
                    <tr class="section"><td colspan="{{ 1 + count($block) + $extra }}">{{ $row['name'] }}</td></tr>
                    @continue
                @endif
                <tr class="{{ $row['type'] === 'account' ? 'account' : $row['style'] }}">
                    <td class="name">{{ $row['name'] }}@if ($row['code'] && $row['type'] !== 'calc') <span style="color:#8a97a4">({{ $row['code'] }})</span>@endif</td>
                    @foreach ($block as $column)
                        @php $v = $row['values'][$column['key']] ?? 0; @endphp
                        <td class="{{ $v < -0.004 ? 'neg' : '' }}">{{ $money($v) }}</td>
                    @endforeach
                    @if ($isLast)
                        <td class="{{ $row['total'] < -0.004 ? 'neg' : '' }}" style="font-weight: bold">{{ $money($row['total']) }}</td>
                        <td>{{ $pct($row['pct']) }}</td>
                        @if ($hasCompare)
                            <td class="{{ $row['prev'] < -0.004 ? 'neg' : '' }}">{{ $money($row['prev']) }}</td>
                            <td class="{{ $row['change'] < -0.004 ? 'neg' : '' }}">{{ $row['change'] > 0.004 ? '+' : '' }}{{ $money($row['change']) }}</td>
                            <td class="{{ ($row['change_pct'] ?? 0) < 0 ? 'neg' : '' }}">{{ ($row['change_pct'] ?? 0) > 0 ? '+' : '' }}{{ $pct($row['change_pct']) }}</td>
                        @endif
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>

        @if ($isLast)
            <div class="notes">
                @lang('databackd-reports-pnl.notes.pct_base')<br>
                @if ($statement['tax']['mode'] === 'actual')
                    @lang('databackd-reports-pnl.notes.tax_actual')
                @else
                    @lang('databackd-reports-pnl.notes.tax_estimated', ['rate' => (int)round($statement['tax']['rate'] * 100)])
                @endif
                @if ($statement['dimension_note'])
                    <br>@lang('databackd-reports-pnl.notes.dimension_' . $statement['dimension_note'])
                @endif
            </div>
        @endif
    </div>
@endforeach
<div class="footer">{{ $company->company_name ?? '' }} · @lang('databackd-reports-pnl.generated_at', ['date' => $generatedAt])</div>
</body>
</html>
