@if (databackd_module('databackd_reports')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.reports.cashflow.index')" :text="__('databackd-reports-cashflow.menu')" />
@endif
