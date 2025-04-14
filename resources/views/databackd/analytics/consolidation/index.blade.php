@extends('layouts.app')

@section('content')
    @php
        $fmt = fn($n) => number_format((float)$n, 2, ',', ' ');
        $fmt0 = fn($n) => number_format((float)$n, 0, ',', ' ') . ' ' . $currency;
        $t = fn($key, $params = []) => __('databackd-analytics-consolidation.' . $key, $params);
        $entityIds = array_keys($entities);
        $colspan = 1 + count($entityIds) + 4;
        $emptyMessage = $t('empty.message');
    @endphp

    <div class="content-wrapper dbk-analytics">
        <x-dbk.page-header :subtitle="$t('subtitle')">
            <x-slot name="actions">
                @if ($rulesUrl)
                    <x-forms.link-secondary :link="$rulesUrl" icon="sliders-h">{{ $t('filters.rules') }}</x-forms.link-secondary>
                @endif
                @if ($exportUrl && $hasData)
                    <x-forms.link-primary :link="$exportUrl" icon="file-excel">{{ $t('filters.export') }}</x-forms.link-primary>
                @endif
            </x-slot>
        </x-dbk.page-header>

        {{-- Filters --}}
        <form method="GET" action="{{ route('databackd.analytics.consolidation.index') }}" id="cons-filter-form" class="dbk-filters">
            <input type="hidden" name="tab" id="cons-tab-input" value="{{ $activeTab }}">
            <div class="dbk-filters-row">
                <div class="dbk-filter dbk-analytics-filter-wide">
                    <label>{{ $t('filters.group') }}</label>
                    <select name="group" class="form-control select-picker" data-size="8">
                        @forelse ($groups as $g)
                            <option value="{{ $g->id }}" @selected($group && $group->id === $g->id)>{{ $g->name }}</option>
                        @empty
                            <option value="">{{ $t('select_group') }}</option>
                        @endforelse
                    </select>
                </div>
                <div class="dbk-filter">
                    <label>{{ $t('filters.period_type') }}</label>
                    <select name="period_type" id="cons-period-type" class="form-control select-picker">
                        @foreach (['month', 'quarter', 'year', 'custom'] as $pt)
                            <option value="{{ $pt }}" @selected($periodInfo['type'] === $pt)>{{ $t('period_types.' . $pt) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="dbk-filter" id="cons-period-wrap" @if ($periodInfo['type'] === 'custom') style="display:none" @endif>
                    <label>{{ $t('filters.period') }}</label>
                    <select name="period" id="cons-period" class="form-control select-picker" data-size="8"
                            @if ($periodInfo['type'] === 'custom') disabled @endif>
                        @foreach ($periodOptions[$periodInfo['type']] ?? [] as $value => $label)
                            <option value="{{ $value }}" @selected($periodInfo['value'] === (string)$value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="dbk-filter dbk-analytics-filter-range" id="cons-custom-wrap" @if ($periodInfo['type'] !== 'custom') style="display:none" @endif>
                    <div class="d-flex">
                        <div class="mr-2 flex-fill">
                            <label class="dbk-analytics-filter-label">{{ $t('filters.from') }}</label>
                            <input type="date" name="from" class="form-control cons-date"
                                   value="{{ $periodInfo['from']->toDateString() }}"
                                   @if ($periodInfo['type'] !== 'custom') disabled @endif>
                        </div>
                        <div class="flex-fill">
                            <label class="dbk-analytics-filter-label">{{ $t('filters.to') }}</label>
                            <input type="date" name="to" class="form-control cons-date"
                                   value="{{ $periodInfo['to']->toDateString() }}"
                                   @if ($periodInfo['type'] !== 'custom') disabled @endif>
                        </div>
                    </div>
                </div>
                <div class="dbk-filters-actions">
                    <button type="submit" id="cons-apply" class="btn btn-primary rounded f-14 p-2"><i class="fa fa-check mr-1"></i>{{ $t('filters.apply') }}</button>
                </div>
            </div>

            @if ($group)
                <div class="dbk-analytics-cons-info">
                    <span class="dbk-chip"><i class="fa fa-layer-group"></i>{{ $t('info.method') }}:
                        <strong>{{ __('databackd-analytics-common.methods.' . $group->method) }}</strong></span>
                    <span class="dbk-chip"><i class="fa fa-euro-sign"></i>{{ $t('info.currency') }}: <strong>{{ $currency }}</strong></span>
                    <span class="dbk-chip"><i class="fa fa-calendar-alt"></i>{{ $t('info.period') }}: <strong>{{ $periodInfo['label'] }}</strong></span>
                    <span class="dbk-chip dbk-analytics-chip-wrap"><i class="fa fa-building"></i>{{ $t('info.entities') }}:
                        <strong>{{ $hasData ? implode(', ', $entities) : '—' }}</strong></span>
                    <span @class(['dbk-chip', 'is-on' => $group->eliminate_intercompany])><i class="fa fa-{{ $group->eliminate_intercompany ? 'check-circle' : 'times-circle' }}"></i>{{ $t($group->eliminate_intercompany ? 'info.intercompany_on' : 'info.intercompany_off') }}</span>
                    <span @class(['dbk-chip', 'is-on' => $group->minority_interest])><i class="fa fa-{{ $group->minority_interest ? 'check-circle' : 'times-circle' }}"></i>{{ $t($group->minority_interest ? 'info.minority_on' : 'info.minority_off') }}</span>
                </div>
            @endif
        </form>

        {{-- AFV (S12): warnings of the run --}}
        @if ($hasData && ($fxMissing || $duplicateNames || $elimMismatch || $withoutEntity))
            <div class="dbk-cons-notices">
                @foreach ($fxMissing as $missing)
                    <div class="dbk-cons-notice is-warning" role="status">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        <div>
                            {{ $t('notices.fx_missing', ['entity' => $missing['entity'], 'from' => $missing['currency'], 'to' => $missing['group_currency'], 'date' => \Carbon\Carbon::parse($missing['date'])->format('d.m.Y')]) }}
                            @if ($fxRatesUrl)
                                <a href="{{ $fxRatesUrl }}" class="dbk-cons-notice-link">{{ $t('notices.fx_link') }}</a>
                            @endif
                        </div>
                    </div>
                @endforeach
                @if ($duplicateNames)
                    <div class="dbk-cons-notice is-warning" role="status">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        <div>
                            {{ $t('notices.duplicate_names', ['names' => implode(', ', $duplicateNames)]) }}
                            @if ($groupUrl)
                                <a href="{{ $groupUrl }}" class="dbk-cons-notice-link">{{ $t('notices.group_link') }}</a>
                            @endif
                        </div>
                    </div>
                @endif
                @if ($elimMismatch)
                    <div class="dbk-cons-notice is-warning" role="status">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        <div>{{ $t('notices.elim_mismatch', ['revenue' => $fmt($elimRevenue) . ' ' . $currency, 'expense' => $fmt($elimExpense) . ' ' . $currency]) }}</div>
                    </div>
                @endif
                @if ($withoutEntity)
                    <div class="dbk-cons-notice is-info" role="status">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <div>{{ trans_choice('databackd-analytics-consolidation.notices.without_entity', $withoutEntity, ['count' => $withoutEntity]) }}</div>
                    </div>
                @endif
            </div>
        @endif

        @if (!$hasData)
            {{-- AFV (S12): one empty state with the next step instead of zero cards and empty tables --}}
            <div class="dbk-card">
                <x-dbk.empty :icon="$emptyReason === 'no_groups' ? 'collection' : ($emptyReason === 'no_entities' ? 'building' : 'calendar-x')"
                             :title="$t('empty.' . $emptyReason . '_title')"
                             :text="$t('empty.' . $emptyReason . '_text', ['period' => $periodInfo['label']])">
                    @if ($emptyReason === 'no_groups')
                        <x-forms.link-primary :link="$createGroupUrl" icon="plus">{{ $t('empty.create_group') }}</x-forms.link-primary>
                    @elseif ($emptyReason === 'no_entities' && $groupUrl)
                        <x-forms.link-primary :link="$groupUrl" icon="building">{{ $t('empty.add_entities') }}</x-forms.link-primary>
                    @endif
                </x-dbk.empty>
            </div>
        @else

        {{-- Summary cards --}}
        <div class="dbk-kpi-grid">
            <x-dbk.kpi :label="$t('cards.revenue')" :value="$fmt0($revenue)" icon="bi-arrow-up-circle" tone="revenue" :info="$t('cards.revenue_info')" />
            <x-dbk.kpi :label="$t('cards.expense')" :value="$fmt0($expense)" icon="bi-arrow-down-circle" tone="expense" :info="$t('cards.expense_info')" />
            <x-dbk.kpi :label="$t('cards.profit')" :value="$fmt0($profit)" icon="bi-graph-up" tone="profit" :info="$t('cards.profit_info')" />
            <x-dbk.kpi :label="$t('cards.eliminated')" :value="$fmt0($eliminated)" icon="bi-shuffle" tone="cash" :info="$t('cards.eliminated_info')" />
        </div>

        {{-- Contribution of the legal entities --}}
        @if ($hasData && $chart['has_revenue'])
            <div class="dbk-grid mb-4">
                <x-dbk.card :title="$t('charts.contribution_title')" :subtitle="$t('amounts_in', ['currency' => $currency]) . ' ' . $t('charts.before_eliminations')" class="dbk-span-8">
                    <x-dbk.chart id="cons-contribution-chart" :label="$t('charts.contribution_title')" />
                </x-dbk.card>
                <x-dbk.card :title="$t('charts.share_title')" :subtitle="$t('charts.before_eliminations')" class="dbk-span-4">
                    <x-dbk.chart id="cons-share-chart" :label="$t('charts.share_title')" />
                </x-dbk.card>
            </div>
        @endif

        {{-- Statements --}}
        <div class="dbk-card dbk-analytics-cons-statements">
            <ul class="nav dbk-tabs cons-tabs" id="cons-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'pnl' ? 'active' : '' }}" data-toggle="tab" data-tab="pnl" href="#cons-pane-pnl" role="tab"
                       aria-controls="cons-pane-pnl" aria-selected="{{ $activeTab === 'pnl' ? 'true' : 'false' }}">
                        <i class="fa fa-chart-bar mr-1"></i><span class="dbk-cons-tab-long">{{ $t('tabs.pnl') }}</span><span class="dbk-cons-tab-short">{{ $t('tabs.pnl_short') }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'balance' ? 'active' : '' }}" data-toggle="tab" data-tab="balance" href="#cons-pane-balance" role="tab"
                       aria-controls="cons-pane-balance" aria-selected="{{ $activeTab === 'balance' ? 'true' : 'false' }}">
                        <i class="fa fa-balance-scale mr-1"></i><span class="dbk-cons-tab-long">{{ $t('tabs.balance') }}</span><span class="dbk-cons-tab-short">{{ $t('tabs.balance_short') }}</span>
                    </a>
                </li>
            </ul>

            <div class="tab-content dbk-analytics-cons-panes">
                {{-- AFV (S12): what the columns mean --}}
                <details class="dbk-cons-explainer">
                    <summary>{{ $t('legend.title') }}</summary>
                    <ul class="dbk-cons-legend">
                        <li><span class="dbk-cons-legend-swatch is-entity"></span><strong>{{ $t('legend.entities') }}</strong> — {{ $t('legend.entities_text') }}</li>
                        <li><span class="dbk-cons-legend-swatch is-elim"></span><strong>{{ $t('columns.eliminations') }}</strong> — {{ $t('legend.eliminations_text') }}</li>
                        <li><span class="dbk-cons-legend-swatch is-adj"></span><strong>{{ $t('columns.adjustments') }}</strong> — {{ $t('legend.adjustments_text') }}</li>
                        <li><span class="dbk-cons-legend-swatch is-adj"></span><strong>{{ $t('columns.minority') }}</strong> — {{ $t('legend.minority_text') }}</li>
                        <li><span class="dbk-cons-legend-swatch is-total"></span><strong>{{ $t('columns.consolidated') }}</strong> — {{ $t('legend.consolidated_text') }}</li>
                    </ul>
                    <p class="dbk-cons-example">{{ $t('legend.example') }}</p>
                    @if ($groupUrl)
                        <a href="{{ $groupUrl }}#rules-help" class="dbk-cons-notice-link">{{ $t('legend.rules_link') }}</a>
                    @endif
                </details>

                <div class="tab-pane fade {{ $activeTab === 'pnl' ? 'show active' : '' }}" id="cons-pane-pnl" role="tabpanel">
                    <p class="dbk-analytics-hint mb-2">
                        {{ $t('amounts_in', ['currency' => $currency]) }} {{ $t('click_hint') }}
                    </p>
                    @include('databackd.analytics.consolidation._table', ['rows' => $pnlRows])
                </div>

                <div class="tab-pane fade {{ $activeTab === 'balance' ? 'show active' : '' }}" id="cons-pane-balance" role="tabpanel">
                    <p class="dbk-analytics-hint mb-2">{{ $t('amounts_in', ['currency' => $currency]) }}</p>
                    @if ($hasData)
                        <div class="dbk-analytics-cons-check {{ $balanceOk ? 'is-good' : 'is-bad' }}">
                            <span class="font-weight-bold">
                                <i class="fa fa-{{ $balanceOk ? 'check-circle' : 'exclamation-triangle' }} mr-2"></i>
                                @if ($balanceOk)
                                    {{ $t('balance_check.ok') }}
                                @else
                                    {{ $t('balance_check.diff', ['difference' => $fmt($balanceCheck['difference']) . ' ' . $currency]) }}
                                @endif
                            </span>
                            <span class="f-13">
                                {{ $t('balance_check.ok_detail', ['assets' => $fmt($balanceCheck['assets']), 'le' => $fmt($balanceCheck['liabilities_equity'])]) }}
                            </span>
                        </div>
                    @endif
                    @include('databackd.analytics.consolidation._table', ['rows' => $balanceRows])
                </div>
            </div>
        </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var periodOptions = @json($periodOptions);
            var $type = $('#cons-period-type');
            var $period = $('#cons-period');

            function syncPeriodControls(rebuild) {
                var type = $type.val();
                var custom = type === 'custom';

                $('#cons-period-wrap').toggle(!custom);
                $('#cons-custom-wrap').toggle(custom);
                $period.prop('disabled', custom);
                $('#cons-custom-wrap input').prop('disabled', !custom);

                if (!custom && rebuild) {
                    var options = periodOptions[type] || {};
                    var html = '';
                    $.each(options, function (value, label) {
                        html += '<option value="' + value + '">' + $('<div>').text(label).html() + '</option>';
                    });
                    $period.html(html);
                }

                if ($period.data('selectpicker')) {
                    $period.selectpicker('refresh');
                }
            }

            $type.on('change', function () {
                syncPeriodControls(true);
            });

            syncPeriodControls(false);

            // Contribution of the legal entities: revenue and profit per entity + share of revenue
            @if ($hasData && $chart['has_revenue'])
                if (window.dbkChart) {
                    var chartData = @json($chart);
                    var currency = @json($currency);
                    var currencyFmt = currency === 'EUR' ? 'money' : function (v) { return dbkChart.fmt.number(v, 2) + ' ' + currency; };
                    // AFV (S12): long entity names on narrow screens - one word per line, all labels shown
                    var narrow = window.matchMedia && window.matchMedia('(max-width: 575.98px)').matches;
                    var barLabels = narrow ? chartData.labels.map(function (l) { return String(l).split(' '); }) : chartData.labels;
                    dbkChart.bar('cons-contribution-chart', {
                        labels: barLabels,
                        datasets: [
                            {label: @json($t('charts.revenue_series')), data: chartData.revenue, role: 'revenue'},
                            {label: @json($t('charts.profit_series')), data: chartData.profit, role: 'profit'}
                        ],
                        unit: currencyFmt,
                        options: {scales: {
                            y: {ticks: {callback: function (v) { return dbkChart.fmt.axis(v); }}},
                            x: {ticks: {autoSkip: false, maxRotation: 0}}
                        }}
                    });
                    dbkChart.donut('cons-share-chart', {
                        labels: chartData.labels,
                        data: chartData.revenue,
                        unit: currencyFmt,
                        center: {value: dbkChart.fmt.compact(chartData.revenue.reduce(function (s, v) { return s + (Number(v) || 0); }, 0)), label: @json($t('charts.revenue_series'))}
                    });
                }
            @endif

            // Keep the active tab in the form (and the URL) so that "Show" does not switch the tab back
            $('#cons-tabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
                var tab = $(e.target).data('tab');
                $('#cons-tabs a[data-toggle="tab"]').attr('aria-selected', 'false');
                $(e.target).attr('aria-selected', 'true');
                $('#cons-tab-input').val(tab);

                if (window.history && history.replaceState) {
                    var url = new URL(window.location.href);
                    url.searchParams.set('tab', tab);
                    history.replaceState(null, '', url.toString());
                }
            });

            // Drill-down: eliminated entries of an account
            var eliminationsUrl = @json($eliminationsUrl);

            $('body').on('click', '.cons-elim-link', function () {
                if (!eliminationsUrl) {
                    return;
                }

                $(MODAL_XL + ' ' + MODAL_HEADING).html('...');
                $.ajaxModal(MODAL_XL, eliminationsUrl + $(this).data('account'));
            });
        });
    </script>
@endpush
