@if (databackd_module('databackd_analytics')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.analytics.ratios.index')" :text="__('databackd-analytics-ratios.menu')"
                 data-dbk-match="account/databackd/analytics/ratio" /> {{-- AFV ui (S12): ratio detail pages --}}
@endif
