{{--
    Shared styles of the annual statements (screen, print page, PDF).
    @include('databackd.gaps.statutory._css', ['mode' => 'screen|print|pdf'])
    dompdf friendly: no flex, no CSS variables.
--}}
@php $mode = $mode ?? 'screen'; @endphp
<style>
    .st-doc { color: #1d2b3a; }
    .st-head { width: 100%; border-collapse: collapse; margin-bottom: {{ $mode === 'pdf' ? '6px' : '10px' }}; }
    .st-head td { vertical-align: top; padding: 0; }
    .st-logo { height: 38px; width: 38px; }
    .st-company { font-size: {{ $mode === 'pdf' ? '13pt' : '18px' }}; font-weight: bold; color: #1d2b3a; }
    .st-meta { font-size: {{ $mode === 'pdf' ? '7.4pt' : '12.5px' }}; color: #5b6b7b; margin-top: 2px; line-height: {{ $mode === 'pdf' ? '1.35' : '1.5' }}; }
    .st-title { font-size: {{ $mode === 'pdf' ? '10pt' : '15px' }}; font-weight: bold; margin-top: {{ $mode === 'pdf' ? '4px' : '8px' }}; color: #1d2b3a; }
    .st-disclaimer { margin-top: 6px; font-size: {{ $mode === 'pdf' ? '7.4pt' : '12px' }}; font-style: italic; color: #8a5a00; }
    .st-right { text-align: right; }

    table.st-table { width: 100%; border-collapse: collapse; }
    table.st-table th { background: #eef2f7; color: #3c4b5c; font-weight: bold; font-size: {{ $mode === 'pdf' ? '7.6pt' : '12px' }}; padding: {{ $mode === 'pdf' ? '3px 6px' : '8px 10px' }}; border-bottom: 2px solid #9fb0c3; text-align: left; vertical-align: bottom; }
    table.st-table th.st-num { text-align: right; }
    table.st-table th small { display: block; font-weight: normal; color: #6b7a89; font-size: 0.92em; }
    table.st-table td { padding: {{ $mode === 'pdf' ? '1.1px 6px' : '6px 10px' }}; border-bottom: 1px solid #e8edf2; font-size: {{ $mode === 'pdf' ? '7.6pt' : '13px' }}; vertical-align: top; }
    table.st-table td.st-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    table.st-table td.st-code { color: #5b6b7b; white-space: nowrap; width: {{ $mode === 'pdf' ? '44px' : '70px' }}; }
    table.st-table td.st-acc { color: #8a97a4; font-size: {{ $mode === 'pdf' ? '7pt' : '11.5px' }}; white-space: nowrap; }
    table.st-table td.st-name.st-l1 { padding-left: {{ $mode === 'pdf' ? '6px' : '10px' }}; }
    table.st-table td.st-name.st-l2 { padding-left: {{ $mode === 'pdf' ? '16px' : '26px' }}; }
    table.st-table td.st-name.st-l3 { padding-left: {{ $mode === 'pdf' ? '28px' : '44px' }}; }
    table.st-table tr.st-heading td { background: #1d3a5f; color: #ffffff; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; border-bottom: 0; }
    table.st-table tr.st-heading td.st-code { color: #ffffff; }
    table.st-table tr.st-subtotal td { font-weight: bold; }
    table.st-table tr.st-subtotal.st-l1 td { background: #f1f5f9; }
    table.st-table tr.st-line.st-l1 td { font-weight: bold; background: #f1f5f9; }
    table.st-table tr.st-line.st-l3 td { color: #3c4b5c; }
    table.st-table tr.st-total td { font-weight: bold; background: #dfe9f5; border-top: 2px solid #1d3a5f; border-bottom: 2px solid #1d3a5f; }
    table.st-table tr.st-result td { font-weight: bold; background: #e6f0fb; border-top: 1px solid #8fb7e8; }
    table.st-table tr.st-memo td { font-style: italic; color: #5b6b7b; }
    table.st-table .st-neg { color: #c9302c; }
    table.st-table .st-zero { color: #aab4be; }

    .st-notes { margin-top: {{ $mode === 'pdf' ? '6px' : '10px' }}; font-size: {{ $mode === 'pdf' ? '6.4pt' : '12px' }}; color: #6b7a89; line-height: {{ $mode === 'pdf' ? '1.35' : '1.55' }}; }
    .st-notes .st-notes-title { font-weight: bold; color: #4a5a6a; margin-bottom: 2px; }
    .st-notes ul { margin: 0; padding-left: 16px; list-style: disc; }

    @if ($mode === 'print')
        @page { size: A4 portrait; margin: 12mm; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; margin: 0; padding: 16px; max-width: 980px; margin: 0 auto; }
        .st-toolbar { position: sticky; top: 0; background: #fff; padding: 8px 0 10px 0; margin-bottom: 12px; border-bottom: 1px solid #e5e7eb; z-index: 5; }
        .st-toolbar button { font-family: inherit; font-size: 13px; padding: 7px 16px; margin-right: 8px; border-radius: 4px; border: 1px solid #1d3a5f; cursor: pointer; background: #1d3a5f; color: #fff; }
        .st-toolbar button.secondary { background: #fff; color: #1d3a5f; }
        .st-page { page-break-after: always; margin-bottom: 40px; }
        .st-page:last-child { page-break-after: auto; }
        .st-foot { margin-top: 14px; font-size: 11px; color: #8a97a4; }
        @media print {
            .st-toolbar { display: none !important; }
            body { padding: 0; max-width: none; }
            .st-page { margin-bottom: 0; }
            table.st-table, table.st-table th, table.st-table td, table.st-table tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.st-table tr { page-break-inside: avoid; }
        }
    @endif
    @if ($mode === 'pdf')
        @page { margin: 22px 28px 30px 28px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { margin: 0; color: #1d2b3a; }
        .st-page { page-break-after: always; }
        .st-page.st-last { page-break-after: auto; }
        .st-foot { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 6.5pt; color: #8a97a4; }
        table.st-table tr { page-break-inside: avoid; }
    @endif
</style>
