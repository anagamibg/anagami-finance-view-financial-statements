<?php

namespace App\Exports\Databackd\Gaps\Margins;

use App\Models\Company;
use App\Services\Databackd\Gaps\Margins\MarginReport;
use App\Services\Databackd\Reports\ReportFilters;

/**
 * PDF of the margins report (A4 landscape, DejaVu Sans for Cyrillic): groups table + products table.
 *
 * Works without a session, a logged in user or a request: the company is passed explicitly, so it can be called from
 * the console as well.
 */
class MarginPdf
{

    /**
     * @return string binary PDF
     */
    public static function render(ReportFilters $f, int $companyId, ?int $categoryId = null): string
    {
        // S12: long reports (daily columns, many accounts) need more than 128 MB in dompdf
        \App\Exports\Databackd\Reports\PivotPdf::raiseMemoryLimit();

        $company = Company::find($companyId);
        $previousLocale = app()->getLocale();

        if ($company && $company->locale && is_dir(resource_path('lang/' . $company->locale))) {
            app()->setLocale($company->locale);
        }

        try {
            $report = (new MarginReport($companyId))->build($f, $categoryId);

            $html = view('databackd.gaps.margins.pdf', [
                'company' => $company,
                'logo' => self::logoPath($company),
                'filters' => $f,
                'report' => $report,
                'currencySymbol' => $company->currency->currency_symbol ?? '€',
                'generatedAt' => now(\App\Services\Databackd\Reports\ReportFilters::companyTimezone($companyId))->format('d.m.Y H:i'), // S12: company time, not UTC
            ])->render();

            $pdf = app('dompdf.wrapper');
            $pdf->setOption('isHtml5ParserEnabled', true);
            $pdf->setOption('isFontSubsettingEnabled', true); // S12: only the used DejaVu glyphs (~860 KB -> tens of KB per page)
            // AFV (S10): the logo is always a local file path (see logoPath()), so remote (http/https) fetch is
            // never needed - kept off although config/dompdf.php enables it (narrows the dompdf remote-fetch/SSRF
            // surface; local paths are validated separately from isRemoteEnabled, see Options::validateLocalUri())
            $pdf->setOption('isRemoteEnabled', false);
            $pdf->setOption('defaultFont', 'DejaVu Sans');
            $pdf->loadHTML($html)->setPaper('a4', 'landscape');

            return $pdf->output();
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * Local path of the company logo (no HTTP request from the PDF renderer); null when no file is found locally.
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
