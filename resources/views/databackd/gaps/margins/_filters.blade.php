{{--
    Filter bar of the margins report (B2, S8). Plain GET form, the state lives in the URL query string.

    $filters      App\Services\Databackd\Reports\ReportFilters
    $categoryId   selected product category (product group) or null
    $categories   [id => name]
    $clients      [id => name]
--}}
@php
    use App\Services\Databackd\Reports\ReportFilters;

    $uid = 'mg' . substr(md5(url()->current()), 0, 6);
    $quickPeriods = array_diff(ReportFilters::PERIODS, ['custom']);
@endphp

<form method="GET" action="{{ route('databackd.gaps.margins.index') }}" id="{{ $uid }}-form" class="dbk-filters mg-noprint">
    <input type="hidden" name="period" id="{{ $uid }}-period" value="{{ $filters->period }}">

    <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 12px">
        <div class="dbk-segmented" role="group" aria-label="@lang('databackd-gaps-margins.filters.period')">
            @foreach ($quickPeriods as $period)
                <button type="button" data-period="{{ $period }}" @class(['is-active' => $filters->period === $period, $uid . '-quick'])>
                    @lang('databackd-gaps-margins.periods.' . $period)
                </button>
            @endforeach
        </div>

        <div class="d-flex align-items-center" style="gap: 8px">
            <label class="mb-0 f-13 text-dark-grey" for="{{ $uid }}-from">@lang('databackd-gaps-margins.filters.from')</label>
            <input type="date" class="form-control form-control-sm {{ $uid }}-date" id="{{ $uid }}-from" name="from"
                value="{{ $filters->from->toDateString() }}" style="width: 150px">
            <label class="mb-0 f-13 text-dark-grey" for="{{ $uid }}-to">@lang('databackd-gaps-margins.filters.to')</label>
            <input type="date" class="form-control form-control-sm {{ $uid }}-date" id="{{ $uid }}-to" name="to"
                value="{{ $filters->to->toDateString() }}" style="width: 150px">
        </div>
    </div>

    <div class="dbk-filters-row">
        <div class="dbk-filter">
            <label for="{{ $uid }}-category">@lang('databackd-gaps-margins.filters.category')</label>
            <select name="category_id" id="{{ $uid }}-category" class="form-control select-picker" data-size="6">
                <option value="">@lang('databackd-gaps-margins.filters.all')</option>
                @foreach ($categories as $id => $name)
                    <option value="{{ $id }}" @selected($categoryId == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="dbk-filter">
            <label for="{{ $uid }}-client">@lang('databackd-gaps-margins.filters.client')</label>
            <select name="client_id" id="{{ $uid }}-client" class="form-control select-picker" data-live-search="true" data-size="6">
                <option value="">@lang('databackd-gaps-margins.filters.all')</option>
                @foreach ($clients as $id => $name)
                    <option value="{{ $id }}" @selected($filters->clientId == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="dbk-filter">
            <label for="{{ $uid }}-compare">@lang('databackd-gaps-margins.filters.compare')</label>
            <select name="compare" id="{{ $uid }}-compare" class="form-control select-picker" data-size="6">
                @foreach (ReportFilters::COMPARE as $c)
                    <option value="{{ $c }}" @selected($filters->compare === $c)>@lang('databackd-gaps-margins.compare.' . $c)</option>
                @endforeach
            </select>
        </div>

        <div class="dbk-filters-actions">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa fa-check mr-1"></i>@lang('databackd-gaps-margins.filters.apply')
            </button>
            <a href="{{ route('databackd.gaps.margins.index') }}" class="btn btn-secondary btn-sm">@lang('databackd-gaps-margins.filters.reset')</a>
        </div>
    </div>
</form>

@push('scripts')
    <script>
        (function () {
            var uid = '{{ $uid }}';
            var form = document.getElementById(uid + '-form');

            // Quick period buttons apply immediately
            $('.' + uid + '-quick').on('click', function () {
                $('#' + uid + '-period').val($(this).data('period'));
                form.submit();
            });

            // Picking dates switches to the custom period
            $('.' + uid + '-date').on('change', function () {
                $('#' + uid + '-period').val('custom');
            });
        })();
    </script>
@endpush
