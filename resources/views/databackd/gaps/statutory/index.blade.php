@extends('layouts.app')

@php
    $nbsp = "\u{00A0}";
    $money = fn($v) => \App\Exports\Databackd\Gaps\Statutory\StatutoryDocument::money($v);
    $amount = fn($v) => number_format((float)$v, 2, ',', $nbsp) . $nbsp . $currencySymbol;
    $info = $doc['info'];
    $balance = $doc['balance'];
    $income = $doc['income'];
    $totals = $balance['totals'];
@endphp

@push('styles')
    @include('databackd.gaps.statutory._css', ['mode' => 'screen'])
    <style>
        .st-screen-tabs .nav-link { color: #5f6b7a; font-size: 14px; }
        .st-screen-tabs .nav-link.active { color: #1d82f5; font-weight: 600; }
        .st-check { border-radius: 4px; padding: 10px 16px; font-size: 14px; }
        .st-check.ok { background: #e9f7ee; color: #1e7a3c; border: 1px solid #bfe5cb; }
        .st-check.bad { background: #fdecec; color: #b42318; border: 1px solid #f5c2c0; }
        .st-check span { margin-right: 22px; white-space: nowrap; }
        .st-paper { padding: 20px 24px 24px; }
        .st-scroll { overflow-x: auto; }

        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            header.main-header, aside, .databackd-statutory-filters, .st-no-print, .preloader-container, .filter-box, .mobile-close-sidebar-panel, .st-screen-tabs { display: none !important; }
            .main-container { margin: 0 !important; padding: 0 !important; background: #fff !important; }
            .content-wrapper { padding: 0 !important; }
            .tab-content > .tab-pane { display: none !important; }
            .tab-content > .tab-pane.active { display: block !important; opacity: 1 !important; }
            .bg-white.rounded { box-shadow: none !important; }
            .st-paper { padding: 0; }
            table.st-table, table.st-table th, table.st-table td, table.st-table tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
@endpush

@section('content')
    <div class="content-wrapper">

        <x-dbk.page-header class="st-no-print" :title="__('databackd-gaps-statutory.title')" :subtitle="__('databackd-gaps-statutory.subtitle')">
            <x-slot name="actions">
                <x-forms.link-secondary :link="route('databackd.gaps.statutory.excel', $query)" class="mr-2 mb-2" icon="file-excel">
                    @lang('databackd-gaps-statutory.actions.excel')
                </x-forms.link-secondary>
                <x-forms.link-secondary :link="route('databackd.gaps.statutory.pdf', $query)" class="mr-2 mb-2" icon="file-pdf">
                    @lang('databackd-gaps-statutory.actions.pdf')
                </x-forms.link-secondary>
                <x-forms.link-secondary :link="route('databackd.gaps.statutory.print', $query)" class="mb-2" icon="print" target="_blank">
                    @lang('databackd-gaps-statutory.actions.print')
                </x-forms.link-secondary>
            </x-slot>
        </x-dbk.page-header>

        {{-- Filters: legal entity or consolidation group, year --}}
        <form method="GET" action="{{ route('databackd.gaps.statutory.index') }}" id="st-filter-form"
              class="databackd-statutory-filters bg-white border rounded p-3 mb-3">
            <input type="hidden" name="tab" id="st-tab-input" value="{{ $activeTab }}">
            <div class="row align-items-end">
                <div class="col-lg-5 col-md-7 mb-2">
                    <label class="f-12 text-dark-grey mb-1" for="st-scope">@lang('databackd-gaps-statutory.filters.scope')</label>
                    <select name="scope" id="st-scope" class="form-control select-picker" data-size="8">
                        @if (!empty($scopes['entities']))
                            <optgroup label="@lang('databackd-gaps-statutory.filters.entities')">
                                @foreach ($scopes['entities'] as $option)
                                    <option value="{{ $option['value'] }}" @selected($scopeValue === $option['value'])>{{ $option['label'] }}@if ($option['eik']) ({{ $option['eik'] }})@endif</option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if (!empty($scopes['groups']))
                            <optgroup label="@lang('databackd-gaps-statutory.filters.groups')">
                                @foreach ($scopes['groups'] as $option)
                                    <option value="{{ $option['value'] }}" @selected($scopeValue === $option['value'])>{{ $option['label'] }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if (!$hasScope)
                            <option value="" selected>@lang('databackd-gaps-statutory.filters.company')</option>
                        @endif
                    </select>
                </div>
                <div class="col-lg-2 col-md-3 col-6 mb-2">
                    <label class="f-12 text-dark-grey mb-1" for="st-year">@lang('databackd-gaps-statutory.filters.year')</label>
                    <select name="year" id="st-year" class="form-control select-picker" data-size="8">
                        @foreach ($years as $y)
                            <option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto mb-2">
                    <button type="submit" class="btn btn-primary rounded f-14 p-2"><i class="fa fa-check mr-1"></i>@lang('databackd-gaps-statutory.filters.apply')</button>
                </div>
            </div>
        </form>

        <div class="alert alert-info f-13 mb-3 st-no-print">
            <i class="fa fa-info-circle mr-1"></i>@lang('databackd-gaps-statutory.document.disclaimer')
        </div>

        @if (!$hasScope)
            <div class="alert alert-warning f-13 mb-3 st-no-print"><i class="fa fa-exclamation-triangle mr-1"></i>@lang('databackd-gaps-statutory.no_scope')</div>
        @endif

        @if (!empty($doc['warnings']))
            <div class="mb-3 st-no-print">
                @foreach ($doc['warnings'] as $warning)
                    <div class="alert alert-{{ $warning['level'] }} f-13 mb-2">
                        <i class="fa fa-exclamation-triangle mr-1"></i>{{ $warning['text'] }}
                    </div>
                @endforeach
            </div>
        @endif

        {{-- KPI --}}
        @php
            $kpiIcon = ['total_assets' => 'bi-bank', 'equity' => 'bi-shield-check', 'net_sales' => 'bi-cash-stack', 'net_profit' => 'bi-piggy-bank'];
            $kpiTone = ['total_assets' => 'cash', 'equity' => 'cash', 'net_sales' => 'revenue', 'net_profit' => 'profit'];
        @endphp
        <div class="dbk-kpi-grid st-no-print">
            @foreach ($kpis as $kpi)
                <x-dbk.kpi
                    :label="__('databackd-gaps-statutory.kpi.' . $kpi['key'])"
                    :value="$amount($kpi['value'])"
                    :icon="$kpiIcon[$kpi['key']] ?? null"
                    :tone="$kpiTone[$kpi['key']] ?? null"
                    :change="$kpi['change_pct']"
                    good="up"
                    :hint="$kpi['change_pct'] !== null ? __('databackd-gaps-statutory.kpi.vs_year', ['year' => $doc['previousYear']]) : null"
                />
            @endforeach
        </div>

        {{-- Check: assets = liabilities + equity --}}
        <div class="st-check {{ $balance['balanced'] ? 'ok' : 'bad' }} mb-3 st-no-print">
            <i class="fa fa-{{ $balance['balanced'] ? 'check-circle' : 'times-circle' }} mr-1"></i>
            <strong>@lang('databackd-gaps-statutory.check.' . ($balance['balanced'] ? 'balanced' : 'not_balanced'))</strong>
            <span class="ml-3">@lang('databackd-gaps-statutory.check.current') ({{ $year }}): {{ $amount($totals['assets']['current']) }} = {{ $amount($totals['liabilities']['current']) }}</span>
            <span>@lang('databackd-gaps-statutory.check.previous') ({{ $doc['previousYear'] }}): {{ $amount($totals['assets']['previous']) }} = {{ $amount($totals['liabilities']['previous']) }}</span>
            @unless ($balance['balanced'])
                <span>@lang('databackd-gaps-statutory.check.difference'): {{ $amount($totals['difference']['current']) }} / {{ $amount($totals['difference']['previous']) }}</span>
            @endunless
        </div>

        @unless ($hasData)
            <x-dbk.empty class="dbk-card mb-3 st-no-print" icon="journal-x" :text="__('databackd-gaps-statutory.empty')" />
        @endunless

        {{-- Statements --}}
        <div class="bg-white rounded b-shadow-4">
            <ul class="nav nav-tabs st-screen-tabs px-3 pt-2" id="st-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'balance' ? 'active' : '' }}" data-toggle="tab" data-tab="balance" href="#st-pane-balance" role="tab">
                        <i class="fa fa-balance-scale mr-1"></i>@lang('databackd-gaps-statutory.tabs.balance')
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'income' ? 'active' : '' }}" data-toggle="tab" data-tab="income" href="#st-pane-income" role="tab">
                        <i class="fa fa-chart-line mr-1"></i>@lang('databackd-gaps-statutory.tabs.income')
                    </a>
                </li>
            </ul>

            <div class="tab-content st-doc">
                <div class="tab-pane fade {{ $activeTab === 'balance' ? 'show active' : '' }}" id="st-pane-balance" role="tabpanel">
                    <div class="st-paper">
                        @include('databackd.gaps.statutory._header', ['info' => $info, 'statement' => $balance, 'logo' => null])
                        <div class="st-scroll">
                            @include('databackd.gaps.statutory._statement', ['statement' => $balance, 'showAccounts' => true])
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade {{ $activeTab === 'income' ? 'show active' : '' }}" id="st-pane-income" role="tabpanel">
                    <div class="st-paper">
                        @include('databackd.gaps.statutory._header', ['info' => $info, 'statement' => $income, 'logo' => null])
                        <div class="st-scroll">
                            @include('databackd.gaps.statutory._statement', ['statement' => $income, 'showAccounts' => true])
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            // Keep the active tab in the form and in the export links, so that "Show" does not switch the tab back
            $('#st-tabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
                $('#st-tab-input').val($(e.target).data('tab'));
            });
        });
    </script>
@endpush
