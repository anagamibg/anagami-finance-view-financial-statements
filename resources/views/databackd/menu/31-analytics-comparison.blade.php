@if (databackd_module('databackd_analytics')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.analytics.comparison.index')" :text="__('databackd-analytics-ratio-detail.menu_comparison')" />
@endif
