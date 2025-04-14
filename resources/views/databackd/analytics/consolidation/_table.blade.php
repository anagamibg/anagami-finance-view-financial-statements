{{--
    One statement of the consolidation worksheet.
    Variables: $rows (ConsolidationExport::statementRows()), $entities, $eliminatedAccounts, $hasData, $currency, $emptyMessage
--}}
@php
    $fmtCell = function ($n) {
        $n = round((float)$n, 2);

        if ($n == 0) {
            return '<span class="dbk-analytics-zero">—</span>';
        }

        $text = number_format($n, 2, ',', ' ');

        return $text;
    };
    // Cell class: numbers right-aligned, negatives in the "bad" colour (the minus sign stays)
    $numClass = fn($n) => 'num' . (round((float)$n, 2) < 0 ? ' neg' : '');
    $rowClass = [
        'section' => 'dbk-row-group dbk-analytics-cons-section',
        'group' => 'dbk-analytics-cons-group',
        'account' => 'dbk-analytics-cons-account',
        'subtotal' => 'dbk-analytics-cons-subtotal',
        'total' => 'dbk-row-total',
        'result' => 'dbk-row-total dbk-analytics-cons-result',
    ];
@endphp

<div class="dbk-table-wrap dbk-table-wrap--scroll dbk-analytics-cons-wrap">
    <table class="dbk-table dbk-table--compact dbk-table--sticky dbk-table--sticky-first cons-table">
        <thead>
            <tr>
                <th scope="col" class="dbk-analytics-cons-account-col">@lang('databackd-analytics-consolidation.columns.account')</th>
                @foreach ($entities as $name)
                    <th scope="col" class="num dbk-cons-entity-col" title="{{ $name }}">{{ $name }}</th>
                @endforeach
                {{-- AFV (S12): the elimination column is tinted, the header explains it --}}
                <th scope="col" class="num dbk-cons-elim-col" title="@lang('databackd-analytics-consolidation.legend.eliminations_text')"><i class="bi bi-shuffle mr-1" aria-hidden="true"></i>@lang('databackd-analytics-consolidation.columns.eliminations')</th>
                <th scope="col" class="num" title="@lang('databackd-analytics-consolidation.legend.adjustments_text')">@lang('databackd-analytics-consolidation.columns.adjustments')</th>
                <th scope="col" class="num" title="@lang('databackd-analytics-consolidation.legend.minority_text')">@lang('databackd-analytics-consolidation.columns.minority')</th>
                <th scope="col" class="num dbk-analytics-cons-consolidated">@lang('databackd-analytics-consolidation.columns.consolidated')</th>
            </tr>
        </thead>
        <tbody>
            @if (!$hasData || empty($rows))
                <tr>
                    <td colspan="{{ $colspan }}" class="dbk-analytics-cons-empty">
                        <x-dbk.empty icon="table" :title="$emptyMessage" :text="__('databackd-analytics-consolidation.empty.hint')" />
                    </td>
                </tr>
            @else
                @foreach ($rows as $row)
                    @php
                        $kind = $row['kind'];
                        $cells = $row['cells'];
                    @endphp
                    @if ($kind === 'section' || $kind === 'group')
                        <tr class="{{ $rowClass[$kind] }}">
                            <td>{{ $row['label'] }}</td>
                            <td colspan="{{ $colspan - 1 }}"></td>
                        </tr>
                    @else
                        <tr class="{{ $rowClass[$kind] ?? '' }}">
                            <td>
                                @if ($row['code'])
                                    <span class="dbk-analytics-cons-code">{{ $row['code'] }}</span>
                                @endif
                                {{ $row['label'] }}
                            </td>
                            @foreach ($entities as $id => $name)
                                <td class="{{ $numClass($cells['entities'][$id] ?? 0) }}">{!! $fmtCell($cells['entities'][$id] ?? 0) !!}</td>
                            @endforeach
                            <td class="{{ $numClass($cells['eliminations']) }} dbk-cons-elim-col">
                                @if ($kind === 'account' && !empty($eliminatedAccounts[$row['account_id']]))
                                    <a href="javascript:;" role="button" class="cons-elim-link" data-account="{{ $row['account_id'] }}"
                                       title="@lang('databackd-analytics-consolidation.show_entries') ({{ __('databackd-analytics-consolidation.entries_count', ['count' => $eliminatedAccounts[$row['account_id']]]) }})">{{ number_format($cells['eliminations'], 2, ',', ' ') }}</a>
                                @else
                                    {!! $fmtCell($cells['eliminations']) !!}
                                @endif
                            </td>
                            <td class="{{ $numClass($cells['adjustments']) }}">{!! $fmtCell($cells['adjustments']) !!}</td>
                            <td class="{{ $numClass($cells['minority']) }}">{!! $fmtCell($cells['minority']) !!}</td>
                            <td class="{{ $numClass($cells['consolidated']) }} dbk-analytics-cons-consolidated">{!! $fmtCell($cells['consolidated']) !!}</td>
                        </tr>
                    @endif
                @endforeach
            @endif
        </tbody>
    </table>
</div>
