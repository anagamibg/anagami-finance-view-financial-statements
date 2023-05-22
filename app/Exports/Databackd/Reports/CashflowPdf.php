<?php

namespace App\Exports\Databackd\Reports;

use App\Models\Company;
use App\Services\Databackd\Reports\CashflowStatement;
use App\Services\Databackd\Reports\ReportFilters;

/**
 * PDF of the cash flow statement (A4 landscape, DejaVu Sans for Cyrillic).
 *
 * Works without a logged in user or session (used by the databackd:send-reports command): the company is passed
 * explicitly. Wide reports (more than 12 columns) are split into blocks of columns, each on its own page.
 */
class CashflowPdf
{

    /** Max period columns in one block of the table. */
    const MAX_COLUMNS = 12;

    /**
     * @return string binary PDF
     */
    public static function render(ReportFilters $f, int $companyId): string
    {
        // S12: long reports (daily columns, many accounts) need more than 128 MB in dompdf
        \App\Exports\Databackd\Reports\PivotPdf::raiseMemoryLimit();

        $statement = (new CashflowStatement($companyId))->build($f);
        $company = Company::withoutGlobalScopes()->find($companyId);

        $columns = $statement['columns'];
        $blocksCount = max(1, (int)ceil(count($columns) / self::MAX_COLUMNS));
        $blocks = array_chunk($columns, max(1, (int)ceil(count($columns) / $blocksCount)));

        $html = view('databackd.reports.cashflow.pdf', [
            'filters' => $f,
            'statement' => $statement,
            'blocks' => $blocks,
            'companyName' => $company->company_name ?? '',
            'logo' => self::logo($company),
            'generatedAt' => now(\App\Services\Databackd\Reports\ReportFilters::companyTimezone($companyId))->format('d.m.Y H:i'), // S12: company time, not UTC
        ])->render();

        $pdf = app('dompdf.wrapper');
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isFontSubsettingEnabled', true); // S12: only the used DejaVu glyphs (~860 KB -> tens of KB per page)
        // SECURITY (S6/P4): the logo is always a local file:// path (see logo()); no remote fetch is needed, so
        // keep it off even though config/dompdf.php's default is enabled (narrows the dompdf 3.1.5 SSRF/remote
        // fetch surface; local file:// paths are validated separately, see Options::validateLocalUri()).
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setPaper('a4', 'landscape');
        $pdf->loadHTML($html);

        return $pdf->output();
    }

    /**
     * Company logo as a local file URL (dompdf does not allow data: URIs in this app), or null.
     * Only logos in the local storage are used.
     */
    protected static function logo(?Company $company): ?string
    {
        $file = $company?->getRawOriginal('light_logo') ?: null;

        if (!$file) {
            return null;
        }

        $path = public_path('user-uploads/app-logo/' . basename($file));

        return is_file($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true)
            ? 'file://' . $path
            : null;
    }

}
