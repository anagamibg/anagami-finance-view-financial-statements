@if (databackd_module('databackd_analytics')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.analytics.builder.index')" :text="__('databackd-analytics-builder.menu')" />
@endif
