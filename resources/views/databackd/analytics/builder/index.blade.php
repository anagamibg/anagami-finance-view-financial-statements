@extends('layouts.app')

@push('styles')
    <style>
        .rb-inactive td { opacity: .55; }
        .rb-inactive td.rb-actions-cell { opacity: 1; }
    </style>
@endpush

@section('filter-section')
    <x-filters.filter-box>
        <div class="task-search d-flex py-1 px-lg-3 px-0 border-right-grey align-items-center">
            <div class="input-group bg-grey rounded">
                <div class="input-group-prepend">
                    <span class="input-group-text border-0 bg-additional-grey">
                        <i class="fa fa-search f-13 text-dark-grey"></i>
                    </span>
                </div>
                <input type="text" class="form-control f-14 p-1 border-additional-grey" id="rb-search"
                    autocomplete="off" placeholder="{{ __('databackd-analytics-builder.search_placeholder') }}">
            </div>
        </div>
        <div class="select-box d-flex py-1 px-lg-2 px-md-2 px-0">
            <div class="btn-group" role="group" id="rb-kind-filter">
                <button type="button" class="btn btn-secondary btn-xs f-13 active" data-kind="all">{{ __('databackd-analytics-builder.filter_all') }}</button>
                <button type="button" class="btn btn-secondary btn-xs f-13" data-kind="system">{{ __('databackd-analytics-builder.filter_system') }}</button>
                <button type="button" class="btn btn-secondary btn-xs f-13" data-kind="custom">{{ __('databackd-analytics-builder.filter_custom') }}</button>
            </div>
        </div>
    </x-filters.filter-box>
@endsection

@section('content')
    @php
        $groupIcons = ['liquidity' => 'tint', 'profitability' => 'chart-line', 'leverage' => 'balance-scale', 'efficiency' => 'tachometer-alt', 'custom' => 'sliders-h'];
        $formatColors = ['ratio' => 'info', 'percent' => 'success', 'days' => 'warning', 'currency' => 'primary'];
        $targetText = function ($r) {
            $fmt = fn($v) => \App\Services\Databackd\Analytics\RatioCalculator::format($r, $v);
            if ($r->target_min !== null && $r->target_max !== null) {
                return __('databackd-analytics-builder.target.range', ['min' => $fmt($r->target_min), 'max' => $fmt($r->target_max)]);
            }
            if ($r->target_min !== null) {
                return __('databackd-analytics-builder.target.min', ['value' => $fmt($r->target_min)]);
            }
            if ($r->target_max !== null) {
                return __('databackd-analytics-builder.target.max', ['value' => $fmt($r->target_max)]);
            }

            return null;
        };
    @endphp

    <div class="content-wrapper dbk-analytics">
        <x-dbk.page-header :subtitle="__('databackd-analytics-builder.subtitle')">
            <x-slot name="actions">
                <div id="table-actions" class="d-flex flex-wrap" style="gap: 8px">
                    @if (\Route::has('databackd.analytics.ratios.index'))
                        <x-forms.link-secondary :link="route('databackd.analytics.ratios.index')" icon="tachometer-alt">
                            {{ __('databackd-analytics-builder.back_to_dashboard') }}
                        </x-forms.link-secondary>
                    @endif
                    <x-forms.link-primary :link="route('databackd.analytics.builder.create')" icon="plus">
                        {{ __('databackd-analytics-builder.add_ratio') }}
                    </x-forms.link-primary>
                </div>
            </x-slot>
        </x-dbk.page-header>

        <div class="dbk-kpi-grid">
            <x-dbk.kpi :label="__('databackd-analytics-builder.summary.total')" :value="$totalRatios" icon="bi-percent" />
            <x-dbk.kpi :label="__('databackd-analytics-builder.summary.system')" :value="$systemRatios" icon="bi-lock" />
            <x-dbk.kpi :label="__('databackd-analytics-builder.summary.custom')" :value="$customRatios" icon="bi-sliders" />
            <x-dbk.kpi :label="__('databackd-analytics-builder.summary.active')" :value="$activeRatios" icon="bi-check-circle" tone="revenue" />
        </div>

        @if ($systemRatios > 0)
            <div class="alert alert-info f-13 mb-3">
                <i class="fa fa-info-circle mr-1"></i>{{ __('databackd-analytics-builder.info_system') }}
            </div>
        @endif

        @forelse ($groups as $group => $items)
            <div class="dbk-card mb-3 rb-group">
                <div class="dbk-card-header has-border align-items-center">
                    <div class="d-flex align-items-center" style="gap: 12px; min-width: 0">
                        <span class="dbk-analytics-group-icon"><i class="fa fa-{{ $groupIcons[$group] ?? 'percentage' }}"></i></span>
                        <div>
                            <h3 class="dbk-card-title">{{ __('databackd-analytics-common.ratio_groups.' . $group) }}</h3>
                            <p class="dbk-card-subtitle">{{ __('databackd-analytics-builder.ratios_count', ['count' => $items->count()]) }}</p>
                        </div>
                    </div>
                </div>

                <div class="dbk-table-wrap is-flush">
                    <table class="dbk-table rb-table">
                        <thead>
                            <tr>
                                <th>{{ __('databackd-analytics-builder.columns.name') }}</th>
                                <th>{{ __('databackd-analytics-builder.columns.formula') }}</th>
                                <th>{{ __('databackd-analytics-builder.columns.format') }}</th>
                                <th>{{ __('databackd-analytics-builder.columns.target') }}</th>
                                <th>{{ __('databackd-analytics-builder.columns.status') }}</th>
                                <th class="num" style="width:150px">{{ __('databackd-analytics-builder.columns.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $ratio)
                                @php
                                    $usedBy = $dependents[$ratio->code] ?? [];
                                    $target = $targetText($ratio);
                                @endphp
                                <tr class="rb-row {{ $ratio->is_active ? '' : 'rb-inactive' }}"
                                    data-kind="{{ $ratio->is_system ? 'system' : 'custom' }}"
                                    data-search="{{ mb_strtolower($ratio->name . ' ' . $ratio->code . ' ' . $ratio->formula) }}">
                                    <td>
                                        <div class="dbk-analytics-ratio-name">{{ $ratio->name }}
                                            @if ($ratio->is_system)
                                                <span class="dbk-badge no-dot ml-1">{{ __('databackd-analytics-builder.badge_system') }}</span>
                                            @else
                                                <span class="dbk-badge is-info no-dot ml-1">{{ __('databackd-analytics-builder.badge_custom') }}</span>
                                            @endif
                                        </div>
                                        <div class="dbk-analytics-code">{{ $ratio->code }}</div>
                                        @if ($usedBy)
                                            <div class="dbk-analytics-hint"><i class="fa fa-link mr-1"></i>{{ __('databackd-analytics-builder.used_by', ['names' => implode(', ', $usedBy)]) }}</div>
                                        @endif
                                    </td>
                                    <td><span class="dbk-analytics-formula-inline" title="{{ $ratio->formula }}">{{ $ratio->formula }}</span></td>
                                    <td>
                                        <span class="dbk-badge no-dot dbk-analytics-format is-{{ $ratio->format }}">
                                            {{ __('databackd-analytics-common.ratio_formats.' . $ratio->format) }}</span>
                                    </td>
                                    <td class="text-nowrap">
                                        @if ($target)
                                            <i class="fa fa-arrow-{{ $ratio->higher_is_better ? 'up text-success' : 'down text-warning' }} dbk-analytics-target-dir"
                                                data-toggle="tooltip"
                                                data-original-title="{{ $ratio->higher_is_better ? __('databackd-analytics-builder.target.higher') : __('databackd-analytics-builder.target.lower') }}"></i>{{ $target }}
                                        @else
                                            <span class="text-lightest">{{ __('databackd-analytics-builder.target.none') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        <span class="dbk-traffic {{ $ratio->is_active ? 'is-good' : '' }} mr-1" aria-hidden="true"></span>
                                        {{ $ratio->is_active ? __('databackd-analytics-builder.status_active') : __('databackd-analytics-builder.status_inactive') }}
                                    </td>
                                    <td class="num rb-actions rb-actions-cell dbk-analytics-actions">
                                        @if ($ratio->is_system)
                                            <a href="{{ route('databackd.analytics.builder.targets', $ratio->id) }}"
                                                class="btn btn-light border openRightModal" data-toggle="tooltip"
                                                data-original-title="{{ __('databackd-analytics-builder.action_targets') }}">
                                                <i class="fa fa-bullseye text-dark-grey"></i></a>
                                        @else
                                            <a href="{{ route('databackd.analytics.builder.edit', $ratio->id) }}"
                                                class="btn btn-light border" data-toggle="tooltip"
                                                data-original-title="{{ __('databackd-analytics-builder.action_edit') }}">
                                                <i class="fa fa-edit text-dark-grey"></i></a>
                                        @endif
                                        <button type="button" class="btn btn-light border rb-toggle"
                                            data-id="{{ $ratio->id }}" data-toggle="tooltip"
                                            data-original-title="{{ $ratio->is_active ? __('databackd-analytics-builder.action_deactivate') : __('databackd-analytics-builder.action_activate') }}">
                                            <i class="fa fa-toggle-{{ $ratio->is_active ? 'on text-success' : 'off text-dark-grey' }}"></i></button>
                                        @unless ($ratio->is_system)
                                            @if ($usedBy)
                                                <span class="d-inline-block" data-toggle="tooltip"
                                                    data-original-title="{{ __('databackd-analytics-builder.action_delete_blocked', ['names' => implode(', ', $usedBy)]) }}">
                                                    <button type="button" class="btn btn-light border" disabled style="pointer-events:none">
                                                        <i class="fa fa-trash text-lightest"></i></button>
                                                </span>
                                            @else
                                                <button type="button" class="btn btn-light border rb-delete"
                                                    data-id="{{ $ratio->id }}" data-toggle="tooltip"
                                                    data-original-title="{{ __('databackd-analytics-builder.action_delete') }}">
                                                    <i class="fa fa-trash text-danger"></i></button>
                                            @endif
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="dbk-card">
                <x-dbk.empty icon="percent" :title="__('databackd-analytics-builder.empty_title')" :text="__('databackd-analytics-builder.empty_text')" />
            </div>
        @endforelse

        <div id="rb-no-results" class="dbk-card mb-3" style="display:none">
            <x-dbk.empty icon="search" :text="__('databackd-analytics-builder.no_results')" />
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function() {
            var csrf = "{{ csrf_token() }}";
            var kind = 'all';

            // Search by name / code / formula and filter by system / custom
            function applyFilter() {
                var q = $.trim($('#rb-search').val()).toLowerCase();
                var visibleGroups = 0;

                $('.rb-group').each(function() {
                    var matches = 0;

                    $(this).find('.rb-row').each(function() {
                        var show = (kind === 'all' || $(this).data('kind') === kind)
                            && (q === '' || String($(this).data('search')).indexOf(q) !== -1);
                        $(this).toggle(show);
                        if (show) matches++;
                    });

                    $(this).toggle(matches > 0);
                    if (matches > 0) visibleGroups++;
                });

                $('#rb-no-results').toggle($('.rb-group').length > 0 && visibleGroups === 0);
            }

            $('#rb-search').on('input', applyFilter);
            $('#rb-kind-filter').on('click', 'button', function() {
                kind = $(this).data('kind');
                $('#rb-kind-filter button').removeClass('active');
                $(this).addClass('active');
                applyFilter();
            });
            $('#filter-form').on('submit', function(e) { e.preventDefault(); });

            // Activate / deactivate
            $('body').on('click', '.rb-toggle', function() {
                var url = "{{ route('databackd.analytics.builder.toggle', ':id') }}".replace(':id', $(this).data('id'));

                $.easyAjax({
                    type: 'POST',
                    url: url,
                    blockUI: true,
                    data: {'_token': csrf},
                    success: function(response) {
                        if (response.status === 'success') {
                            window.location.reload();
                        }
                    }
                });
            });

            // Delete a custom ratio (the server refuses when another ratio points to it with REF[...])
            $('body').on('click', '.rb-delete', function() {
                var id = $(this).data('id');

                Swal.fire({
                    title: "{{ __('databackd-analytics-builder.messages.delete_title') }}",
                    text: "{{ __('databackd-analytics-builder.messages.delete_text') }}",
                    icon: 'warning',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: "{{ __('databackd-analytics-builder.messages.delete_confirm') }}",
                    cancelButtonText: "@lang('app.cancel')",
                    customClass: {
                        confirmButton: 'btn btn-primary mr-3',
                        cancelButton: 'btn btn-secondary'
                    },
                    showClass: {
                        popup: 'swal2-noanimation',
                        backdrop: 'swal2-noanimation'
                    },
                    buttonsStyling: false
                }).then(function(result) {
                    if (result.isConfirmed) {
                        var url = "{{ route('databackd.analytics.builder.destroy', ':id') }}".replace(':id', id);

                        $.easyAjax({
                            type: 'POST',
                            url: url,
                            blockUI: true,
                            data: {'_token': csrf, '_method': 'DELETE'},
                            success: function(response) {
                                if (response.status === 'success') {
                                    window.location.reload();
                                }
                            }
                        });
                    }
                });
            });
        });
    </script>
@endpush
