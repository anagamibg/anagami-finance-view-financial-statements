@extends('layouts.app')


@section('content')
    @php
        $t = fn(string $key, array $r = []) => __('databackd-analytics-ratio-detail.' . $key, $r);
        $query = array_filter([
            'period_type' => $period['type'] === 'custom' ? null : $period['type'],
            'period' => $period['type'] === 'custom' ? null : $period['key'],
            'from' => $period['type'] === 'custom' ? $period['from']->toDateString() : null,
            'to' => $period['type'] === 'custom' ? $period['to']->toDateString() : null,
        ]);
        $fmt = fn($v) => \App\Services\Databackd\Analytics\RatioCalculator::format($ratio, $v);
    @endphp

    <div class="content-wrapper dbk-analytics">
        {{-- Header --}}
        <x-dbk.page-header :title="$ratio->name">
            <div class="dbk-analytics-detail-tags">
                <span class="dbk-chip dbk-analytics-mono">{{ $ratio->code }}</span>
                <span class="dbk-chip">@lang('databackd-analytics-common.ratio_groups.' . $ratio->group)</span>
                <span class="dbk-page-meta">{{ $ratio->is_system ? $t('detail.system_ratio') : $t('detail.custom_ratio') }}</span>
            </div>
            <p class="dbk-page-subtitle">
                {{ $period['label'] }}
                @if ($entityFilter['entity'])
                    &middot; {{ $entityFilter['entity']->name }}
                @elseif ($entityFilter['group'])
                    &middot; @lang('databackd-analytics-common.consolidated'): {{ $entityFilter['group']->name }}
                @endif
            </p>
            <x-slot name="actions">
                @if ($dashboardUrl)
                    <a href="{{ $dashboardUrl }}" class="btn btn-secondary rounded f-14 p-2">
                        <i class="fa fa-arrow-left mr-1"></i> {{ $t('actions.back_to_dashboard') }}
                    </a>
                @endif
                <a href="{{ $comparisonUrl }}" class="btn btn-secondary rounded f-14 p-2">
                    <i class="fa fa-columns mr-1"></i> {{ $t('actions.comparison') }}
                </a>
            </x-slot>
        </x-dbk.page-header>

        @include('databackd.analytics.ratio-detail._filters', [
            'action' => route('databackd.analytics.ratio-detail.show', $ratio->id),
            'period' => $period,
            'periodOptions' => $periodOptions,
            'showEntity' => true,
            'entityValue' => $entityFilter['value'],
            'entities' => $entities,
            'consolidationGroups' => $consolidationGroups,
        ])

        {{-- What the value means, in plain words (system ratios), or why it is missing --}}
        @if ($readText)
            <div class="dbk-analytics-read">
                <i class="bi bi-lightbulb" aria-hidden="true"></i>
                <div><strong>{{ __('databackd-analytics-ratios.how_to_read') }}:</strong> {{ $readText }}</div>
            </div>
        @elseif ($value === null)
            <div class="dbk-analytics-read is-warning">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <div>{{ $explain['error'] ? $t('detail.calc_error') : $nullReason }}</div>
            </div>
        @endif

        {{-- KPI row --}}
        <div class="dbk-kpi-grid dbk-kpi-grid--3 dbk-analytics-detail-kpis">
            <x-dbk.kpi :label="$t('detail.value_for_period')" :value="$valueText" icon="bi-bullseye" class="dbk-analytics-kpi is-{{ $status }}">
                <div class="dbk-kpi-foot">
                    <span class="dbk-analytics-status is-{{ $status }}">
                        <span class="dbk-traffic is-{{ $status }}" aria-hidden="true"></span>
                        {{ $statusLabel }}
                    </span>
                </div>
            </x-dbk.kpi>
            <x-dbk.kpi :label="$t('detail.previous_period')" :value="$previousValue === null ? '—' : $previousText" icon="bi-clock-history">
                <div class="dbk-kpi-foot">
                    <span>{{ $previousPeriodLabel }}</span>
                    @if ($change)
                        <span>&middot; {{ $t('detail.change') }}:</span>
                        <span class="dbk-delta is-{{ ['good' => 'good', 'bad' => 'bad'][$change['direction']] ?? 'neutral' }}">
                            <i class="fa fa-{{ $change['arrow'] }} mr-1"></i>{{ $change['text'] }}
                        </span>
                    @endif
                </div>
            </x-dbk.kpi>
            <x-dbk.kpi :label="$t('detail.target')" :value="$targetText ?? '—'" icon="bi-flag"
                       :hint="$targetText ? ($ratio->higher_is_better ? $t('detail.higher_is_better') : $t('detail.lower_is_better')) : $t('detail.no_target')" />
        </div>

        <div class="dbk-grid">
            {{-- 18 month trend --}}
            <x-dbk.card :title="$t('detail.chart_title')" class="dbk-span-8">
                @if ($chart['has_data'])
                    <x-dbk.chart id="rd-trend-chart" size="lg" :label="$t('detail.chart_title')" />
                    <p class="dbk-analytics-hint mt-2 mb-0">{{ $t('detail.chart_hint') }}</p>
                @else
                    <x-dbk.empty icon="graph-up" size="sm" :text="$t('detail.no_chart_data')" />
                @endif
            </x-dbk.card>

            {{-- Formula and description --}}
            <x-dbk.card :title="$t('detail.formula')" class="dbk-span-4">
                <div class="dbk-analytics-formula">{{ $ratio->formula }}</div>
                <dl class="dbk-dl dbk-analytics-dl-stack mt-3">
                    @if ($calculation)
                        <dt>{{ $t('detail.calculation') }}</dt>
                        <dd><span class="dbk-analytics-mono">{{ $calculation }}</span> = <strong>{{ $valueText }}</strong></dd>
                    @endif
                    <dt>{{ $t('detail.description') }}</dt>
                    <dd class="font-weight-normal">{{ $ratio->description ?: $t('detail.no_description') }}</dd>
                </dl>
            </x-dbk.card>

            {{-- Components --}}
            <x-dbk.card :title="$t('detail.components_title')" :subtitle="$t('detail.components_hint') . (($ratio->format !== 'ratio' || collect($components)->contains(fn($c) => !$c['is_ref'])) ? ' ' . $t('detail.currency_hint') : '')" class="dbk-span-6" body-class="pt-3">
                <div class="dbk-table-wrap">
                    <table class="dbk-table dbk-table--compact">
                        <thead>
                            <tr>
                                <th>{{ $t('detail.col_variable') }}</th>
                                <th>{{ $t('detail.col_description') }}</th>
                                <th class="num">{{ $t('detail.col_value') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($components as $c)
                                <tr>
                                    <td><span class="dbk-analytics-key">{{ $c['key'] }}</span></td>
                                    <td>{{ $c['label'] }}</td>
                                    <td class="num">{{ $c['text'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="dbk-text-muted">—</td></tr>
                            @endforelse
                            <tr class="dbk-row-total">
                                <td colspan="2">{{ $t('detail.result') }}</td>
                                <td class="num"><span class="dbk-badge is-{{ ['good' => 'good', 'warning' => 'warning', 'bad' => 'bad'][$status] ?? 'none' }}">{{ $valueText }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-dbk.card>

            {{-- By entity --}}
            <x-dbk.card :title="$t('detail.by_entity_title')" class="dbk-span-6" body-class="pt-3">
                <div class="dbk-table-wrap">
                    <table class="dbk-table dbk-table--compact">
                        <thead>
                            <tr>
                                <th>{{ $t('detail.col_entity') }}</th>
                                <th class="num">{{ $t('detail.col_value') }}</th>
                                <th>{{ $t('detail.col_status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entityRows as $row)
                                <tr @class(['dbk-row-total' => $row['id'] === null, 'is-current' => (string)$row['id'] === (string)$entityFilter['value']])>
                                    <td>
                                        @if ($row['id'])
                                            <a href="{{ route('databackd.analytics.ratio-detail.show', [$ratio->id] + $query + ['entity' => $row['id']]) }}">{{ $row['name'] }}</a>
                                        @else
                                            <a href="{{ route('databackd.analytics.ratio-detail.show', [$ratio->id] + $query) }}">{{ $row['name'] }}</a>
                                        @endif
                                    </td>
                                    <td class="num">{{ $row['text'] }}</td>
                                    <td>
                                        <span class="dbk-analytics-status is-{{ $row['status'] }}">
                                            <span class="dbk-traffic is-{{ $row['status'] }}" aria-hidden="true"></span>
                                            {{ $row['status_label'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-dbk.card>
        </div>
    </div>
@endsection

@if ($chart['has_data'])
    @push('scripts')
        <script>
            $(function () {
                var cd = @json($chart);
                var targetMinText = @json($ratio->target_min !== null ? $fmt($ratio->target_min) : null);
                var targetMaxText = @json($ratio->target_max !== null ? $fmt($ratio->target_max) : null);
                var n = cd.labels.length;
                var fill = function (v) { return Array.apply(null, Array(n)).map(function () { return v; }); };
                var hasMin = cd.target_min !== null, hasMax = cd.target_max !== null;
                var suffix = {percent: '\u00a0%', days: '\u00a0' + @json(__('databackd-analytics-common.days_unit'))}[cd.format] || '';
                var decimals = {percent: 1, days: 0}[cd.format];
                var statusColor = function (s) { return dbkChart.role(s === 'none' ? 'neutral' : s) || dbkChart.role('neutral'); };
                var datasets = [{
                    label: @json($t('detail.chart_value')), data: cd.values, role: 'profit', borderWidth: 2, tension: .25,
                    spanGaps: false, order: 1,
                    pointBackgroundColor: cd.statuses.map(statusColor),
                    pointRadius: cd.selected.map(function (s) { return s ? 6 : 3; }),
                    pointHoverRadius: 7
                }];

                if (hasMin) {
                    datasets.push({label: @json($t('detail.chart_target_min')), data: fill(cd.target_min), role: 'budget', dashed: true, borderWidth: 1.5, pointHoverRadius: 0, order: 3, kind: 'min'});
                }
                if (hasMax) {
                    datasets.push({
                        label: hasMin ? @json($t('detail.chart_corridor')) : @json($t('detail.chart_target_max')), data: fill(cd.target_max),
                        role: 'budget', dashed: true, borderWidth: 1.5, pointHoverRadius: 0, order: 3, kind: 'max',
                        area: hasMin, fill: hasMin ? '-1' : false
                    });
                }

                dbkChart.line('rd-trend-chart', {
                    labels: cd.labels,
                    datasets: datasets,
                    legend: true,
                    // axis ticks in the locale format (1,5 / 1.5); the tooltip uses the server texts
                    unit: function (v) {
                        var d = decimals !== undefined ? decimals : (Math.abs(v) >= 100 || v % 1 === 0 ? 0 : 2);
                        return dbkChart.fmt.number(v, Math.abs(v) >= 100 ? 0 : d) + suffix;
                    },
                    options: {
                        plugins: {
                            legend: {labels: {filter: function (item) { return item.text !== @json($t('detail.chart_target_min')); }}},
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var k = ctx.dataset.kind;
                                        if (!k) { return ' ' + ctx.dataset.label + ': ' + cd.texts[ctx.dataIndex]; }
                                        return ' ' + (k === 'min' ? @json($t('detail.chart_target_min')) : @json($t('detail.chart_target_max'))) + ': ' + (k === 'min' ? targetMinText : targetMaxText);
                                    }
                                }
                            }
                        }
                    }
                });
            });
        </script>
    @endpush
@endif
