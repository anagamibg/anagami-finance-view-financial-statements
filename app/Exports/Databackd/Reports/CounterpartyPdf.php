<?php

namespace App\Exports\Databackd\Reports;

use App\Models\Company;
use App\Services\Databackd\Reports\ReportFilters;

/**
 * PDF of the counterparties report (turnover, documents, share; no sparkline). Safe for console use:
 * the company is passed explicitly, no session / logged in user is needed (used by databackd:send-reports).
 */
class CounterpartyPdf
{

    /**
     * @return string binary PDF
     */
    public static function render(ReportFilters $f, int $companyId, string $side = 'revenue'): string
    {
        PivotPdf::raiseMemoryLimit();
        $side = CounterpartyData::normalizeSide($side);
        $listing = (new CounterpartyData($companyId))->listing($f, $side);
        $company = Company::withoutGlobalScopes()->find($companyId);

        $logo = null;
        $file = $company && $company->logo ? public_path('user-uploads/app-logo/' . $company->logo) : null;

        // A file path (not a data URI) is what dompdf renders reliably
        if ($file && is_file($file)) {
            $logo = $file;
        }

        $html = view('databackd.reports.counterparties.pdf', [
            'listing' => $listing,
            'filters' => $f,
            'side' => $side,
            'companyName' => $company->company_name ?? '',
            'logo' => $logo,
            'generatedAt' => now(ReportFilters::companyTimezone($companyId)), // S12: company time, not UTC
        ])->render();

        $pdf = app('dompdf.wrapper');
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isFontSubsettingEnabled', true); // S12: ~860 KB -> tens of KB
        // SECURITY (S6/P4): the logo is always a local file path (see above); no remote fetch is needed, so keep
        // it off even though config/dompdf.php's default is enabled (narrows the dompdf 3.1.5 SSRF/remote fetch
        // surface; local paths are validated separately, see Options::validateLocalUri()).
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setPaper('a4', !empty($listing['compare']) || $side === 'all' ? 'landscape' : 'portrait');
        $pdf->loadHTML($html);

        return $pdf->output();
    }

}
