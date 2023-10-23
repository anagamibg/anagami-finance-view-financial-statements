{{--
    Table of one statement (balance sheet or income statement).
    $statement  StatutoryReport::balanceSheet() / incomeStatement() result
    $showAccounts  show the "Accounts" column (screen); the print page and the PDF omit it
--}}
@php
    $money = fn($v) => \App\Exports\Databackd\Gaps\Statutory\StatutoryDocument::money($v);
    $showAccounts = $showAccounts ?? false;
    $isBalance = $statement['kind'] === 'balance';
    $currentLabel = $isBalance ? \Carbon\Carbon::parse($statement['dates']['current'])->format('d.m.Y') : (string)$statement['year'];
    $previousLabel = $isBalance ? \Carbon\Carbon::parse($statement['dates']['previous'])->format('d.m.Y') : (string)$statement['previous_year'];
    $colspan = 2 + ($showAccounts ? 1 : 0);
@endphp
<table class="st-table">
    <thead>
    <tr>
        <th>@lang('databackd-gaps-statutory.columns.code')</th>
        <th>@lang('databackd-gaps-statutory.columns.item')</th>
        @if ($showAccounts)
            <th>@lang('databackd-gaps-statutory.columns.accounts')</th>
        @endif
        <th class="st-num">@lang('databackd-gaps-statutory.columns.current')<small>{{ $currentLabel }}</small></th>
        <th class="st-num">@lang('databackd-gaps-statutory.columns.previous')<small>{{ $previousLabel }}</small></th>
    </tr>
    </thead>
    <tbody>
    @foreach ($statement['rows'] as $row)
        @if ($row['type'] === 'heading')
            <tr class="st-heading">
                <td class="st-code">{{ $row['code'] }}</td>
                <td colspan="{{ $colspan - 1 + 2 }}" class="st-name">{{ $row['name'] }}</td>
            </tr>
            @continue
        @endif
        <tr class="st-{{ $row['type'] }} st-l{{ $row['level'] }}">
            <td class="st-code">{{ $row['code'] }}</td>
            <td class="st-name st-l{{ $row['level'] }}">{{ $row['name'] }}</td>
            @if ($showAccounts)
                <td class="st-acc">{{ implode(', ', $row['accounts']) }}</td>
            @endif
            <td class="st-num {{ $row['current'] < -0.004 ? 'st-neg' : (abs($row['current']) < 0.005 ? 'st-zero' : '') }}">{{ $money($row['current']) }}</td>
            <td class="st-num {{ $row['previous'] < -0.004 ? 'st-neg' : (abs($row['previous']) < 0.005 ? 'st-zero' : '') }}">{{ $money($row['previous']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="st-notes">
    <div class="st-notes-title">@lang('databackd-gaps-statutory.notes.title')</div>
    <ul>
        @foreach ($statement['notes'] as $note)
            <li>{{ $note }}</li>
        @endforeach
        @if ($showAccounts)
            <li>@lang('databackd-gaps-statutory.notes.accounts_column')</li>
        @endif
    </ul>
</div>
