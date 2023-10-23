{{--
    Document header of one statement: legal entity / group, EIK, title, comparative date, currency, disclaimer.
    $info (StatutoryReport::scopeInfo), $statement (balanceSheet() / incomeStatement()), $logo (optional file path)
--}}
@php
    [$docTitle, $docComparative] = \App\Exports\Databackd\Gaps\Statutory\StatutoryDocument::titles($statement);
@endphp
<table class="st-head">
    <tr>
        <td style="width: 78%">
            <div class="st-company">{{ $info['name'] }}</div>
            <div class="st-meta">
                @foreach (\App\Exports\Databackd\Gaps\Statutory\StatutoryDocument::infoLines($info) as $line)
                    {{ $line }}<br>
                @endforeach
            </div>
            <div class="st-title">{{ $docTitle }}</div>
            <div class="st-meta">
                {{ $docComparative }}<br>
                @lang('databackd-gaps-statutory.document.currency_note', ['currency' => $statement['currency']])
            </div>
            <div class="st-disclaimer">@lang('databackd-gaps-statutory.document.disclaimer')</div>
        </td>
        <td class="st-right" style="width: 22%">
            @if (!empty($logo))
                <img src="{{ $logo }}" class="st-logo" alt="">
            @endif
        </td>
    </tr>
</table>
