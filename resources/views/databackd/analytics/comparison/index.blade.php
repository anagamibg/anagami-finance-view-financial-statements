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
        $fmtTarget = function ($ratio) {
            $f = fn($v) => \App\Services\Databackd\Analytics\RatioCalculator::format($ratio, $v);
            if ($ratio->target_min !== null && $ratio->target_max !== null) { return $f($ratio->target_min) . ' – ' . $f($ratio->target_max); }
            if ($ratio->target_min !== null) { return '≥ ' . $f($ratio->target_min); }
            if ($ratio->target_max !== null) { return '≤ ' . $f($ratio->target_max); }
            return '—';
        };
        $summaryColumns = $entities->map(fn($e) => ['key' => $e->id, 'name' => $e->name])->all();
        if ($entities->count() > 1) { $summaryColumns[] = ['key' => 'group', 'name' => $t('comparison.col_group')]; }
        $statusIcons = ['good' => 'bi-check-circle-fill', 'warning' => 'bi-exclamation-triangle-fill', 'bad' => 'bi-x-circle-fill', 'none' => 'bi-dash'];
        // "good" also covers values better than the target range
        $statusText = fn($st) => $st === 'good' ? __('databackd-analytics-ratios.summary.good') : __('databackd-analytics-common.statuses.' . $st);
    @endphp

    <div class="content-wrapper dbk-analytics">
        <x-dbk.page-header :title="$t('comparison_title')" :subtitle="$t('comparison_subtitle') . ' · ' . $period['label']">
            <x-slot name="actions">
                @if ($dashboardUrl)
                    <a href="{{ $dashboardUrl }}" class="btn btn-secondary rounded f-14 p-2">
                        <i class="fa fa-arrow-left mr-1"></i> {{ $t('actions.back_to_dashboard') }}
                    </a>
                @endif
                <a href="{{ $exportUrl }}" class="btn btn-primary rounded f-14 p-2">
                    <i class="fa fa-file-excel mr-1"></i> {{ $t('actions.export') }}
                </a>
            </x-slot>
        </x-dbk.page-header>

        @include('databackd.analytics.ratio-detail._filters', [
            'action' => route('databackd.analytics.comparison.index'),
            'period' => $period,
            'periodOptions' => $periodOptions,
        ])

        @if ($entities->isEmpty())
            <div class="dbk-card">
                <x-dbk.empty icon="building" :title="$t('comparison.no_entities_title')" :text="$t('comparison.no_entities_hint')" />
            </div>
        @elseif ($ratioCount === 0)
            <div class="dbk-card">
                <x-dbk.empty icon="graph-up" :title="$t('comparison.no_ratios_title')" :text="$t('comparison.no_ratios_hint')" />
            </div>
        @else
            {{-- Summary: how many ratios are within the target, per entity and for the group --}}
            <div class="dbk-analytics-cmp-summary">
                @foreach ($summaryColumns as $col)
                    @php $s = $summary[$col['key']]; $judged = $s['good'] + $s['warning'] + $s['bad']; @endphp
                    <div class="dbk-kpi dbk-analytics-cmp-sum">
                        <p class="dbk-kpi-label">{{ $t('comparison.summary_title') }}</p>
                        <div class="dbk-analytics-cmp-name" title="{{ $col['name'] }}">{{ $col['name'] }}</div>
                        <div class="dbk-kpi-value">{{ $s['good'] }}<span class="dbk-analytics-cmp-of"> / {{ $judged }}</span></div>
                        <div class="dbk-progress mt-2" title="{{ $t('comparison.summary_of', ['good' => $s['good'], 'total' => $judged]) }}">
                            @foreach (['good', 'warning', 'bad'] as $st)
                                @if ($judged && $s[$st])<span class="is-{{ $st }}" style="width: {{ round($s[$st] / $judged * 100, 1) }}%"></span>@endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <x-dbk.card :title="$t('comparison.ratios_count', ['count' => $ratioCount])" body-class="p-0 pt-3">
                <div class="dbk-table-wrap is-flush dbk-table-wrap--scroll">
                    <table class="dbk-table dbk-table--sticky dbk-table--sticky-first dbk-analytics-heatmap">
                        <thead>
                            <tr>
                                <th>{{ $t('comparison.col_ratio') }}</th>
                                <th>{{ $t('comparison.col_target') }}</th>
                                @foreach ($entities as $e)
                                    <th class="num">{{ $e->name }}</th>
                                @endforeach
                                <th class="num dbk-analytics-heat-total" title="{{ $t('comparison.group_hint') }}">{{ $t('comparison.col_group') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($groups as $groupKey => $rows)
                                <tr class="dbk-row-group">
                                    <td>@lang('databackd-analytics-common.ratio_groups.' . $groupKey)</td>
                                    <td colspan="{{ 2 + $entities->count() }}"></td>
                                </tr>
                                @foreach ($rows as $row)
                                    @php $r = $row['ratio']; @endphp
                                    <tr>
                                        <td class="dbk-analytics-heat-ratio">
                                            <a href="{{ route('databackd.analytics.ratio-detail.show', [$r->id] + $query) }}" title="{{ $t('actions.open_detail') }}">{{ $r->name }}</a>
                                        </td>
                                        <td class="dbk-analytics-heat-target">{{ $fmtTarget($r) }}</td>
                                        @foreach ($entities as $e)
                                            @php $c = $row['cells'][$e->id]; @endphp
                                            <td class="num dbk-analytics-heat is-{{ $c['status'] }}" title="{{ $statusText($c['status']) }}">
                                                <a href="{{ route('databackd.analytics.ratio-detail.show', [$r->id] + $query + ['entity' => $e->id]) }}"><i class="bi {{ $statusIcons[$c['status']] ?? 'bi-dash' }}" aria-hidden="true"></i>{{ $c['text'] }}</a>
                                            </td>
                                        @endforeach
                                        <td class="num dbk-analytics-heat dbk-analytics-heat-total is-{{ $row['group']['status'] }}" title="{{ $statusText($row['group']['status']) }}">
                                            <a href="{{ route('databackd.analytics.ratio-detail.show', [$r->id] + $query) }}"><i class="bi {{ $statusIcons[$row['group']['status']] ?? 'bi-dash' }}" aria-hidden="true"></i>{{ $row['group']['text'] }}</a>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-slot name="footer">
                    <div class="dbk-analytics-heat-legend">
                        @foreach (['good', 'warning', 'bad', 'none'] as $st)
                            <span class="dbk-analytics-heat is-{{ $st }}"><i class="bi {{ $statusIcons[$st] }}" aria-hidden="true"></i>{{ $statusText($st) }}</span>
                        @endforeach
                        <span>{{ $t('comparison.legend') }}</span>
                    </div>
                </x-slot>
            </x-dbk.card>
        @endif
    </div>
@endsection
