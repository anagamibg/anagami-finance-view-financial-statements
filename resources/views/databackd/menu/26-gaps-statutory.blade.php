@if (databackd_module('databackd_reports')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.gaps.statutory.index')" :text="__('databackd-gaps-statutory.menu')" />
@endif
