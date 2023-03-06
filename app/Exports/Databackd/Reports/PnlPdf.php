<?php

namespace App\Exports\Databackd\Reports;

use App\Models\Company;
use App\Services\Databackd\Reports\PnlStatement;
use App\Services\Databackd\Reports\ReportFilters;

/**
 * PDF of the profit and loss statement (A4 landscape, DejaVu Sans for Cyrillic).
 *
 * Works without a session, a logged in user or a request: the company is passed explicitly, so it can be called from
 * the console (databackd:send-reports). With more than MAX_COLUMNS period columns the table is split into blocks.
 */
class PnlPdf
{

    const MAX_COLUMNS = 12;

    /**
     * @return string binary PDF
     */
    public static function render(ReportFilters $f, int $companyId): string
    {
        // S12: long reports (daily columns, many accounts) need more than 128 MB in dompdf
        \App\Exports\Databackd\Reports\PivotPdf::raiseMemoryLimit();

        $company = Company::find($companyId);
        $previousLocale = app()->getLocale();

        if ($company && $company->locale && is_dir(resource_path('lang/' . $company->locale))) {
            app()->setLocale($company->locale);
        }

        try {
            $statement = (new PnlStatement($companyId))->build($f);

            $html = view('databackd.reports.pnl.pdf', [
                'company' => $company,
                'logo' => self::logoPath($company),
                'filters' => $f,
                'statement' => $statement,
                'blocks' => array_chunk($statement['columns'], self::MAX_COLUMNS),
                'generatedAt' => now(\App\Services\Databackd\Reports\ReportFilters::companyTimezone($companyId))->format('d.m.Y H:i'), // S12: company time, not UTC
            ])->render();

            $pdf = app('dompdf.wrapper');
            $pdf->setOption('isHtml5ParserEnabled', true);
            $pdf->setOption('isFontSubsettingEnabled', true); // S12: only the used DejaVu glyphs (~860 KB -> tens of KB per page)
            // SECURITY (S6/P4): the logo is always a local file path (see logoPath()); remote (http/https) fetch
            // is never needed here, so keep it off even though config/dompdf.php's default is enabled - this
            // narrows the dompdf 3.1.5 remote-fetch/SSRF attack surface without affecting local file rendering
            // (file:// and plain local paths are validated separately from isRemoteEnabled, see Options::validateLocalUri()).
            $pdf->setOption('isRemoteEnabled', false);
            $pdf->setOption('defaultFont', 'DejaVu Sans');
            $pdf->loadHTML($html)->setPaper('a4', 'landscape');

            return $pdf->output();
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * Local path of the company logo (no HTTP request from the PDF renderer; data: URIs are not rendered by the
     * installed dompdf); null when the file is not found locally.
     */
    protected static function logoPath(?Company $company): ?string
    {
        $candidates = [];

        if ($company) {
            foreach ([$company->light_logo, $company->logo] as $file) {
                if ($file) {
                    $candidates[] = public_path('user-uploads/app-logo/' . $file);
                }
            }
        }

        $candidates[] = public_path('img/worksuite-logo.png');

        foreach ($candidates as $path) {
            if (is_file($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif'], true)) {
                return $path;
            }
        }

        return null;
    }

}
