<style>
    .mg-report .mg-table th.mg-sortable { cursor: pointer; user-select: none; }
    .mg-report .mg-table th.mg-sortable:after { content: ' \2195'; color: #c3ccd6; }
    .mg-report .mg-table th.mg-asc:after { content: ' \25B2'; color: var(--dbk-accent, #1d6fd6); }
    .mg-report .mg-table th.mg-desc:after { content: ' \25BC'; color: var(--dbk-accent, #1d6fd6); }
    .mg-report .mg-table thead th { white-space: normal; vertical-align: bottom; }
    .mg-report .mg-table td.mg-name { min-width: 220px; white-space: normal; }
    .mg-report .mg-table .mg-sub { display: block; font-size: 12px; color: var(--dbk-text-muted, #66717f); }
    .mg-report .mg-table .mg-share { display: flex; align-items: center; gap: 8px; }
    .mg-report .mg-table .mg-share .dbk-progress { width: 64px; min-width: 64px; flex: none; }
    .mg-report .mg-table tr.mg-missing > td.mg-name { box-shadow: inset 3px 0 0 var(--dbk-warning, #b26a00); }
    .mg-report .mg-alert { display: flex; gap: 12px; align-items: flex-start; }
    .mg-report .mg-alert .mg-alert-icon { font-size: 20px; line-height: 24px; }
    .mg-report .mg-alert-products { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .mg-report .mg-alert-products a { text-decoration: none; }
    .mg-report .mg-search { max-width: 280px; }
    @media print {
        aside, #header, .main-header, .mg-noprint, .preloader-container, .mobile-close-sidebar-panel { display: none !important; }
        .body-wrapper, .main-container { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        .content-wrapper { padding: 0 !important; }
        .mg-report .dbk-table-wrap { overflow: visible !important; max-height: none !important; }
        .mg-report { font-size: 11px; }
    }
</style>
