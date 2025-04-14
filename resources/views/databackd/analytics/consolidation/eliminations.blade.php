@php
    $fmt = fn($n) => number_format((float)$n, 2, ',', ' ');
    $t = fn($key, $params = []) => __('databackd-analytics-consolidation.' . $key, $params);
@endphp

<div class="modal-header">
    <h5 class="modal-title" id="modelHeading">
        {{ $t('modal.title') }}
        @if ($account)
            <span class="text-dark-grey f-w-400">— <span class="dbk-analytics-cons-code">{{ $account->code }}</span> {{ $account->name }}</span>
        @endif
    </h5>
    <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('databackd-misc.close') }}"><span aria-hidden="true">×</span></button>
</div>
<div class="modal-body">
    @if ($entries->isEmpty())
        <div class="text-center py-5">
            <i class="fa fa-random f-36 text-lightest mb-3 d-block"></i>
            <div class="f-15 text-dark">{{ $t('modal.empty') }}</div>
        </div>
    @else
        <p class="f-13 text-dark-grey mb-2">
            {{ $t('modal.summary', ['count' => $totalCount, 'period' => $periodLabel, 'total' => $fmt($total) . ' ' . $entryCurrency]) }}
            @if ($totalCount > $limit)
                <br><span class="text-lightest">{{ $t('modal.truncated', ['limit' => $limit]) }}</span>
            @endif
        </p>
        {{-- AFV (S12): the worksheet amount (group currency, after FX rate and share) next to the sum of the entries --}}
        @if ($worksheetAmount !== null)
            <p class="f-13 mb-2"><strong>{{ $t('modal.worksheet_amount', ['amount' => $fmt($worksheetAmount) . ' ' . $currency]) }}</strong></p>
        @endif
        @if ($derived)
            <p class="f-13 text-dark-grey mb-2">{{ $t('modal.derived_note') }}</p>
        @elseif ($worksheetAmount !== null && abs(abs($worksheetAmount) - abs($total)) >= 0.01)
            <p class="f-13 text-dark-grey mb-2">{{ $t('modal.difference_note') }}</p>
        @endif

        <div class="dbk-table-wrap dbk-table-wrap--scroll dbk-analytics" style="max-height: 480px">
            <table class="dbk-table dbk-table--compact dbk-table--sticky dbk-table--sticky-first">
                <thead>
                    <tr>
                        <th>{{ $t('modal.columns.date') }}</th>
                        @unless ($account)
                            <th>{{ $t('modal.columns.account') }}</th>
                        @endunless
                        <th>{{ $t('modal.columns.entity') }}</th>
                        <th>{{ $t('modal.columns.counterparty') }}</th>
                        <th>{{ $t('modal.columns.document_no') }}</th>
                        <th>{{ $t('modal.columns.description') }}</th>
                        <th class="num">{{ $t('modal.columns.amount_in', ['currency' => $entryCurrency]) }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td class="f-13 text-nowrap">{{ $entry->entry_date?->format('d.m.Y') }}</td>
                            @unless ($account)
                                <td class="f-13 text-nowrap"><span class="dbk-analytics-cons-code">{{ $entry->account->code ?? '' }}</span> {{ $entry->account->name ?? '' }}</td>
                            @endunless
                            <td class="f-13">{{ $entry->entity->name ?? '—' }}</td>
                            <td class="f-13">{{ $entry->counterparty_name ?: '—' }}</td>
                            <td class="f-13 text-nowrap"><span class="dbk-chip">{{ $entry->document_no ?: '—' }}</span></td>
                            <td class="f-13">{{ $entry->description ?: '—' }}</td>
                            <td @class(['num', 'neg' => $entry->amount_base < 0])>{{ $fmt($entry->amount_base) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="dbk-row-total">
                        <th colspan="{{ $account ? 5 : 6 }}" class="num">{{ $t('modal.total') }} ({{ $entryCurrency }})</th>
                        <th class="num">{{ $fmt($total) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
<div class="modal-footer">
    <x-forms.button-cancel data-dismiss="modal" class="border-0">{{ $t('modal.close') }}</x-forms.button-cancel>
</div>
