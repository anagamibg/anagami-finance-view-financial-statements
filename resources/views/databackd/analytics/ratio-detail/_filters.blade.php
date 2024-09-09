{{--
    Period (+ legal entity) filter bar of the ratio detail and comparison screens. Plain GET form.

    @include('databackd.analytics.ratio-detail._filters', [...])

    $action               form action
    $period               RatiosExport::resolvePeriod() result
    $periodOptions        RatiosExport::periodOptions() of the current type
    $showEntity           (optional) show the legal entity select
    $entityValue          (optional) selected entity: all | id | group:<id>
    $entities             (optional) legal entities (id, name)
    $consolidationGroups  (optional) consolidation groups (id, name), empty until the consolidated ratios exist
--}}
@php
    $showEntity = $showEntity ?? false;
    $entityValue = $entityValue ?? 'all';
    $entities = $entities ?? collect();
    $consolidationGroups = $consolidationGroups ?? collect();
    $currentType = $period['type'];
    $currentKey = $period['key'];
@endphp

<form method="GET" action="{{ $action }}" class="rd-filters dbk-filters" id="rd-filter-form">
    @if ($currentType === 'custom')
        <input type="hidden" name="from" value="{{ $period['from']->toDateString() }}" class="rd-custom-range">
        <input type="hidden" name="to" value="{{ $period['to']->toDateString() }}" class="rd-custom-range">
    @endif
    <div class="dbk-filters-row">
        <div class="dbk-segmented" role="group">
            @foreach (['month', 'quarter', 'year'] as $type)
                <button type="submit" name="period_type" value="{{ $type }}"
                    @class(['is-active' => $currentType === $type]) aria-pressed="{{ $currentType === $type ? 'true' : 'false' }}">
                    @lang('databackd-analytics-ratio-detail.filters.period_types.' . $type)
                </button>
            @endforeach
        </div>

        <div class="dbk-filter">
            <label for="rd-period">@lang('databackd-analytics-ratio-detail.filters.period')</label>
            <select class="form-control select-picker rd-auto" name="period" id="rd-period" data-size="8">
                @if ($currentType === 'custom')
                    <option value="" selected>{{ $period['label'] }}</option>
                @endif
                @foreach ($periodOptions as $opt)
                    <option value="{{ $opt['key'] }}" @selected($currentType !== 'custom' && $opt['key'] === $currentKey)>{{ $opt['label'] }}</option>
                @endforeach
            </select>
        </div>

        @if ($showEntity)
            <div class="dbk-filter">
                <label for="rd-entity">@lang('databackd-analytics-ratio-detail.filters.entity')</label>
                <select class="form-control select-picker rd-auto" name="entity" id="rd-entity" data-size="8">
                    <option value="all" @selected($entityValue === 'all')>@lang('databackd-analytics-ratio-detail.filters.all_entities')</option>
                    @foreach ($entities as $entity)
                        <option value="{{ $entity->id }}" @selected((string)$entity->id === (string)$entityValue)>{{ $entity->name }}</option>
                    @endforeach
                    @if ($consolidationGroups->isNotEmpty())
                        <optgroup label="@lang('databackd-analytics-ratio-detail.filters.consolidated')">
                            @foreach ($consolidationGroups as $group)
                                <option value="group:{{ $group->id }}" @selected($entityValue === 'group:' . $group->id)>{{ $group->name }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </div>
        @endif

        @if ($period['partial'])
            <span class="dbk-analytics-hint ml-lg-auto">
                <i class="fa fa-info-circle mr-1"></i> @lang('databackd-analytics-ratio-detail.period_partial', ['date' => $period['to']->format('d.m.Y')])
            </span>
        @endif
    </div>
</form>

@push('scripts')
    <script>
        $(function () {
            // The selected period type travels with the period select
            var form = $('#rd-filter-form');
            var type = @json($currentType === 'custom' ? 'month' : $currentType);
            form.find('.rd-auto').on('change', function () {
                if ($(this).attr('name') === 'period') {
                    form.find('.rd-custom-range').remove();
                }
                if (!form.find('input[name=period_type]').length) {
                    form.append($('<input type="hidden" name="period_type">').val(type));
                }
                form[0].submit();
            });
        });
    </script>
@endpush
