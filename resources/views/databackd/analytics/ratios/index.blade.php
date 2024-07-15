@extends('layouts.app')

@push('styles')
    <style>
        .ratio-tooltip .tooltip-inner { max-width: 340px; text-align: left; }
        .ratio-tooltip code { color: #ffd98a; background: transparent; font-size: 12px; word-break: break-word; }
        .ratio-tooltip .text-lightest { color: #c5ced8 !important; }
    </style>
@endpush

@section('filter-section')
    <x-filters.filter-box>
        <div class="select-box d-flex py-2 px-lg-2 px-md-2 px-0 border-right-grey border-right-grey-sm-0">
            <p class="mb-0 pr-2 f-14 text-dark-grey d-flex align-items-center">{{ __('databackd-analytics-ratios.filters.period_type') }}</p>
            <div class="select-status">
                <select class="form-control select-picker" name="period_type" id="period_type" data-size="5">
                    @foreach ($periodTypes as $type)
                        <option value="{{ $type }}" @selected($type === $periodType)>{{ __('databackd-analytics-common.periodicity.' . $type) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="select-box d-flex py-2 px-lg-2 px-md-2 px-0 border-right-grey border-right-grey-sm-0">
            <p class="mb-0 pr-2 f-14 text-dark-grey d-flex align-items-center">{{ __('databackd-analytics-ratios.filters.period') }}</p>
            <div class="select-status">
                <select class="form-control select-picker" name="period" id="period" data-size="8" data-live-search="true">
                    @foreach ($periodOptions as $key => $label)
                        <option value="{{ $key }}" @selected($key === $periodKey)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="select-box d-flex py-2 px-lg-2 px-md-2 px-0 border-right-grey border-right-grey-sm-0">
            <p class="mb-0 pr-2 f-14 text-dark-grey d-flex align-items-center">{{ __('databackd-analytics-ratios.filters.entity') }}</p>
            <div class="select-status">
                <select class="form-control select-picker" name="entity" id="entity" data-size="8">
                    <option value="all" @selected($entityKey === 'all')>{{ __('databackd-analytics-ratios.filters.all_entities') }}</option>
                    @foreach ($entities as $entity)
                        <option value="{{ $entity->id }}" @selected($entityKey === (string)$entity->id)>{{ $entity->name }}</option>
                    @endforeach
                    {{-- Phase 2: consolidated views (entity=group:<id>) are listed here --}}
                    @if (!empty($consolidationGroups))
                        <optgroup label="{{ __('databackd-analytics-ratios.filters.consolidated_group') }}">
                            @foreach ($consolidationGroups as $group)
                                <option value="group:{{ $group['id'] }}" @selected($entityKey === 'group:' . $group['id'])>
                                    {{ __('databackd-analytics-ratios.filters.consolidated_group') }}: {{ $group['name'] }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </div>
        </div>

        <div class="select-box d-flex py-1 px-lg-2 px-md-2 px-0 align-items-center">
            <x-forms.link-secondary :link="$resetUrl" class="btn-xs" icon="times-circle">
                {{ __('databackd-analytics-ratios.actions.reset') }}
            </x-forms.link-secondary>
        </div>
    </x-filters.filter-box>
@endsection

@section('content')
    @php
        $statusIcons = ['good' => 'bi-check-circle-fill', 'warning' => 'bi-exclamation-triangle-fill', 'bad' => 'bi-x-circle-fill', 'none' => 'bi-dash-circle'];
        // Bullet gauge: where the value sits against the target corridor (0 … 120 % of the largest of value / bounds)
        $gauge = function ($ratio, $value) {
            $min = $ratio->target_min;
            $max = $ratio->target_max;
            if ($value === null || ($min === null && $max === null)) {
                return null;
            }
            $scale = max(abs((float)$value), abs((float)($max ?? 0)), abs((float)($min ?? 0))) * 1.2;
            if ($scale <= 0) {
                return null;
            }
            $pct = fn($v) => round(max(0, min(100, $v / $scale * 100)), 1);

            return [
                'value' => $pct((float)$value),
                'from' => $min !== null ? $pct((float)$min) : 0,
                'to' => $max !== null ? $pct((float)$max) : 100,
            ];
        };
    @endphp

    <div class="content-wrapper dbk-analytics">
        <x-dbk.page-header :subtitle="__('databackd-analytics-ratios.subtitle')">
            <p class="dbk-page-meta mb-0 mt-1">
                <i class="bi bi-calendar3 mr-1"></i>
                {{ __('databackd-analytics-ratios.period_caption', ['from' => $from->translatedFormat($dateFormat), 'to' => $to->translatedFormat($dateFormat)]) }}
                @if ($prevLabel)
                    &middot; {{ __('databackd-analytics-ratios.compared_with', ['period' => $prevLabel]) }}
                @endif
                &middot; <i class="bi bi-building mr-1"></i>
                {{ __('databackd-analytics-ratios.entity_caption', ['entity' => $entityName ?? __('databackd-analytics-ratios.filters.all_entities')]) }}
            </p>
            <x-slot name="actions">
                @if ($comparisonUrl)
                    <x-forms.link-secondary :link="$comparisonUrl" icon="columns">
                        {{ __('databackd-analytics-ratios.actions.comparison') }}
                    </x-forms.link-secondary>
                @endif
                @if ($builderUrl)
                    <x-forms.link-secondary :link="$builderUrl" icon="calculator">
                        {{ __('databackd-analytics-ratios.actions.builder') }}
                    </x-forms.link-secondary>
                @endif
                @if ($exportUrl)
                    <x-forms.link-primary :link="$exportUrl" icon="file-excel">
                        {{ __('databackd-analytics-ratios.actions.export') }}
                    </x-forms.link-primary>
                @endif
            </x-slot>
        </x-dbk.page-header>

        @if ($ratiosInstalled)
            <div class="alert alert-info f-13">{{ __('databackd-analytics-ratios.installed_notice') }}</div>
        @endif

        @if ($summary['total'] === 0)
            <div class="dbk-card">
                <x-dbk.empty icon="graph-up" :title="__('databackd-analytics-ratios.empty.title')" :text="__('databackd-analytics-ratios.empty.text')" />
            </div>
        @else
            @unless ($hasValues)
                <div class="alert alert-warning f-13">{{ __('databackd-analytics-ratios.no_data_notice') }}</div>
            @endunless

            <div class="dbk-kpi-grid ratio-summary">
                <x-dbk.kpi :label="__('databackd-analytics-ratios.summary.total')" :value="$summary['total']" icon="bi-speedometer2" />
                @foreach (['good' => ['bi-check-circle', 'revenue'], 'warning' => ['bi-exclamation-triangle', 'forecast'], 'bad' => ['bi-x-circle', 'expense']] as $st => [$stIcon, $stTone])
                    <x-dbk.kpi :label="__('databackd-analytics-ratios.summary.' . $st)" :value="$summary[$st]" :icon="$stIcon" :tone="$stTone" />
                @endforeach
            </div>

            <details class="dbk-card dbk-analytics-help" id="ratio-help">
                <summary class="dbk-analytics-help-summary">
                    <i class="bi bi-question-circle" aria-hidden="true"></i>
                    <span>{{ __('databackd-analytics-ratios.help.title') }}</span>
                    <i class="bi bi-chevron-down dbk-analytics-help-chevron" aria-hidden="true"></i>
                </summary>
                <div class="dbk-analytics-help-body">
                    <p class="mb-2">{{ __('databackd-analytics-ratios.help.intro') }}</p>
                    <ul class="dbk-analytics-help-list">
                        <li><span class="dbk-traffic is-good" aria-hidden="true"></span> {{ __('databackd-analytics-ratios.help.good') }}</li>
                        <li><span class="dbk-traffic is-warning" aria-hidden="true"></span> {{ __('databackd-analytics-ratios.help.warning') }}</li>
                        <li><span class="dbk-traffic is-bad" aria-hidden="true"></span> {{ __('databackd-analytics-ratios.help.bad') }}</li>
                        <li><i class="bi bi-arrow-up-short" aria-hidden="true"></i> {{ __('databackd-analytics-ratios.help.delta') }}</li>
                        <li><span class="dbk-analytics-help-band" aria-hidden="true"></span> {{ __('databackd-analytics-ratios.help.gauge') }}</li>
                        <li><i class="bi bi-hand-index" aria-hidden="true"></i> {{ __('databackd-analytics-ratios.help.detail') }}</li>
                    </ul>
                    @if ($builderUrl)
                        <p class="mb-0 mt-2"><a href="{{ $builderUrl }}">{{ __('databackd-analytics-ratios.help.targets') }}</a></p>
                    @endif
                </div>
            </details>

            @foreach ($groups as $groupKey => $cards)
                <div class="dbk-analytics-group">
                    <span class="dbk-analytics-group-icon" aria-hidden="true"><i class="fa fa-{{ $groupIcons[$groupKey] ?? 'chart-bar' }}"></i></span>
                    <h2 class="dbk-section-title">{{ __('databackd-analytics-common.ratio_groups.' . $groupKey) }}</h2>
                    <span class="dbk-analytics-group-count">{{ __('databackd-analytics-ratios.ratios_count', ['count' => count($cards)]) }}</span>
                </div>

                <div class="dbk-analytics-ratio-grid">
                    @foreach ($cards as $card)
                        @php
                            $ratio = $card['ratio'];
                            $tag = $card['url'] ? 'a' : 'div';
                            $spark = $card['spark'];
                            $delta = $card['delta'];
                            $g = $gauge($ratio, $card['value']);
                            $sparkId = 'ratio-spark-' . $ratio->id;
                            $sparkPoints = $spark ? array_map(fn($p) => ['l' => $p['label'], 'v' => round($spark['h'] - $p['y'], 2), 't' => $p['text']], $spark['points']) : [];
                        @endphp
                        <{{ $tag }} @if ($card['url']) href="{{ $card['url'] }}" @endif
                            class="dbk-analytics-ratio is-{{ $card['status'] }}"
                            data-toggle="tooltip" data-html="true" data-placement="top"
                            title="{{ $card['tooltip'] }}">

                            <div class="dbk-analytics-ratio-head">
                                <div class="dbk-analytics-ratio-name">{{ $ratio->name }}</div>
                                <i class="bi bi-info-circle dbk-analytics-ratio-info" aria-hidden="true"></i>
                            </div>

                            <div class="dbk-analytics-ratio-main">
                                <div class="dbk-analytics-ratio-value">{{ $card['formatted'] }}</div>
                                @if ($delta)
                                    <span class="dbk-delta is-{{ $delta['tone'] === 'neutral' ? 'neutral' : $delta['tone'] }}">
                                        @if ($delta['direction'] === 'up')
                                            <i class="bi bi-arrow-up-short"></i>
                                        @elseif ($delta['direction'] === 'down')
                                            <i class="bi bi-arrow-down-short"></i>
                                        @else
                                            <i class="bi bi-dash"></i>
                                        @endif{{ $delta['text'] }}
                                    </span>
                                @else
                                    <span class="dbk-delta is-neutral">{{ __('databackd-analytics-ratios.no_change') }}</span>
                                @endif
                            </div>

                            <div class="dbk-analytics-status is-{{ $card['status'] }}">
                                <span class="dbk-traffic is-{{ $card['status'] }}" aria-hidden="true"></span>
                                <i class="bi {{ $statusIcons[$card['status']] ?? 'bi-dash-circle' }}" aria-hidden="true"></i>
                                {{ $card['status_label'] }}
                            </div>

                            @if ($card['read'])
                                <p class="dbk-analytics-ratio-read">{{ $card['read'] }}</p>
                            @elseif ($card['value'] === null)
                                <p class="dbk-analytics-ratio-read">{{ __('databackd-analytics-ratios.not_calculated') }}</p>
                            @endif

                            @if ($g)
                                <div class="dbk-analytics-gauge" aria-hidden="true">
                                    <span class="dbk-analytics-gauge-band" style="left: {{ $g['from'] }}%; width: {{ max(0, $g['to'] - $g['from']) }}%"></span>
                                    <span class="dbk-analytics-gauge-fill is-{{ $card['status'] }}" style="width: {{ $g['value'] }}%"></span>
                                </div>
                            @endif

                            <div class="dbk-analytics-ratio-meta">
                                {{ __('databackd-analytics-ratios.target') }}: <strong>{{ $card['target'] }}</strong>
                                @if ($card['previous_formatted'])
                                    &middot; {{ __('databackd-analytics-ratios.previous') }}: {{ $card['previous_formatted'] }}
                                @endif
                            </div>

                            @if ($spark)
                                <div class="dbk-analytics-spark"
                                     data-status="{{ $card['status'] }}"
                                     data-points="{{ json_encode($sparkPoints) }}">
                                    <canvas id="{{ $sparkId }}" role="img" aria-label="{{ __('databackd-analytics-ratios.sparkline_title') }}"></canvas>
                                </div>
                                <div class="dbk-analytics-ratio-meta dbk-analytics-spark-axis">
                                    <span>{{ $spark['points'][0]['label'] }}</span>
                                    <span>{{ __('databackd-analytics-ratios.sparkline_title') }}</span>
                                    <span>{{ $spark['last']['label'] }}</span>
                                </div>
                            @endif
                        </{{ $tag }}>
                    @endforeach
                </div>
            @endforeach
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('[data-toggle="tooltip"]').tooltip({
                container: 'body',
                boundary: 'window',
                template: '<div class="tooltip ratio-tooltip" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>'
            });

            // The "how to read" panel remembers whether it was open (per browser)
            var help = document.getElementById('ratio-help');
            if (help) {
                // open on the first visit on a wide screen; on a phone it would push the cards far down
                var saved = null;
                try { saved = localStorage.getItem('dbk-ratio-help'); } catch (e) { saved = null; }
                help.open = saved ? saved === 'open' : window.matchMedia('(min-width: 768px)').matches;
                help.addEventListener('toggle', function () {
                    try { localStorage.setItem('dbk-ratio-help', help.open ? 'open' : 'closed'); } catch (e) { /* storage blocked */ }
                });
            }

            $('#period_type').on('change', function () {
                // The list of periods depends on the type: let the server pick the default period
                $('#period').prop('disabled', true);
                $('#filter-form').trigger('submit');
            });

            $('#period, #entity').on('change', function () {
                $('#filter-form').trigger('submit');
            });

            // 12-month sparklines: the series is drawn from the server geometry (shape only), the tooltip shows the formatted value
            if (window.dbkChart) {
                $('.dbk-analytics-spark').each(function () {
                    var points = $(this).data('points') || [];
                    var status = $(this).data('status');
                    var texts = {};
                    points.forEach(function (p) { texts[p.v] = p.t; });
                    dbkChart.sparkline($(this).find('canvas')[0], {
                        data: points.map(function (p) { return p.v; }),
                        labels: points.map(function (p) { return p.l; }),
                        color: dbkChart.role(status === 'none' ? 'profit' : status),
                        unit: function (v) { return texts[v] !== undefined ? texts[v] : ''; }
                    });
                });
            }
        });
    </script>
@endpush
