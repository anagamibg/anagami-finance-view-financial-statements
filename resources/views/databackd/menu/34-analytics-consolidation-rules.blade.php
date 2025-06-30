@if (databackd_module('databackd_analytics')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.analytics.consolidation-rules.index')" :text="__('databackd-analytics-consolidation-rules.menu')" />
@endif
