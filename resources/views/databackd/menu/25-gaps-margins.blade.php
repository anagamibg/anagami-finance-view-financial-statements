@if (databackd_module('databackd_reports') && in_array('invoices', user_modules()) && in_array('products', user_modules()) && (in_array('admin', user_roles()) || (user()->permission('view_invoices') === 'all' && user()->permission('view_product') === 'all'))) {{-- AFV (S8): package module; (S10): same rule as MarginReportController::canViewReport() --}}
<x-sub-menu-item :link="route('databackd.gaps.margins.index')" :text="__('databackd-gaps-margins.menu')" />
@endif
