<?php

namespace App\Exports\Databackd\Gaps\Statutory;

use App\Models\Company;

/**
 * PDF of the annual statements (A4 portrait, DejaVu Sans for Cyrillic): the balance sheet and the income statement,
 * each on its own page.
 *
 * Works without a session, a logged in user or a request: the company is passed explicitly (same approach as
 * App\Exports\Databackd\Reports\PnlPdf). The logo is passed as a file path - the installed dompdf does not render
 * data: URIs.
 */
class StatutoryPdf
{

    /**
     * @return string binary PDF
     */
    public static function render(int $year, array $scope, int $companyId): string
    {
        // S12: long reports (daily columns, many accounts) need more than 128 MB in dompdf
        \App\Exports\Databackd\Reports\PivotPdf::raiseMemoryLimit();

        $company = Company::find($companyId);
        $previousLocale = app()->getLocale();

        if ($company && $company->locale && is_dir(resource_path('lang/' . $company->locale))) {
            app()->setLocale($company->locale);
        }

        try {
            $html = view('databackd.gaps.statutory.pdf', StatutoryDocument::prepare($year, $scope, $companyId) + [
                'logo' => self::logoPath($company),
            ])->render();

            $pdf = app('dompdf.wrapper');
            $pdf->setOption('isHtml5ParserEnabled', true);
            $pdf->setOption('isFontSubsettingEnabled', true); // S12: only the used DejaVu glyphs (~860 KB -> tens of KB per page)
            // AFV (S10): the logo is always a local file path (see logoPath()), so remote (http/https) fetch is
            // never needed - kept off although config/dompdf.php enables it (narrows the dompdf remote-fetch/SSRF
            // surface; local paths are validated separately from isRemoteEnabled, see Options::validateLocalUri())
            $pdf->setOption('isRemoteEnabled', false);
            $pdf->setOption('defaultFont', 'DejaVu Sans');
            $pdf->loadHTML($html)->setPaper('a4', 'portrait');

            return $pdf->output();
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * Local path of the company logo; null when the file is not found locally.
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
