<?php

namespace App\Http\Controllers\Databackd\Gaps\Margins;

use App\Exports\Databackd\Gaps\Margins\MarginExport;
use App\Exports\Databackd\Gaps\Margins\MarginPdf;
use App\Http\Controllers\AccountBaseController;
use App\Services\Databackd\Gaps\Margins\MarginReport;
use App\Services\Databackd\Reports\ReportFilters;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Margins by products and services: revenue (from the invoices), cost (quantity x product cost price), gross margin and
 * margin % per product and per product group, charts, Excel / PDF / print.
 */
class MarginReportController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-gaps-margins.title');
            abort_403(in_array('client', user_roles()));
            // AFV (S10): the report (and its Excel / PDF) shows invoice revenue per product and client, credit
            // notes and product cost prices - the same data as the AFV invoices and products lists, so it needs
            // full access to both (admin always has it)
            abort_403(!$this->canViewReport());

            return $next($request);
        });
    }

    /** AFV (S10): admin, or view_invoices = all and view_product = all. */
    protected function canViewReport(): bool
    {
        // audit 2026-10-30 (G11): invoice revenue by product - needs the AFV invoices and products modules (admin included)
        if (!in_array('invoices', user_modules()) || !in_array('products', user_modules())) {
            return false;
        }

        if (in_array('admin', user_roles())) {
            return true;
        }

        return user()->permission('view_invoices') === 'all' && user()->permission('view_product') === 'all';
    }

    public function index(Request $request)
    {
        $this->pageTitle = __('databackd-gaps-margins.title');
        $this->filters = $this->filtersFromRequest($request);
        $this->categoryId = $this->categoryFromRequest($request);

        $service = new MarginReport(company()->id);
        $this->report = $service->build($this->filters, $this->categoryId);
        $this->charts = MarginReport::chartData($this->report);
        $this->categoryOptions = $service->categories();
        $this->clientOptions = $service->clients();
        $this->query = $this->queryOf($this->filters, $this->categoryId);
        $this->generatedAt = now();
        $this->currencySymbol = company()->currency->currency_symbol ?? '€';

        // links from the "missing cost price" warnings to the product
        $editPermission = user()->permission('edit_product');
        $viewPermission = user()->permission('view_product');
        $this->canEditAll = $editPermission === 'all';
        $this->canEditOwn = $editPermission === 'added';
        $this->canView = in_array($viewPermission, ['all', 'added']);
        $this->userId = user()->id;

        return view('databackd.gaps.margins.index', $this->data);
    }

    public function excel(Request $request)
    {
        $filters = $this->filtersFromRequest($request);

        return Excel::download(new MarginExport($filters, company()->id, $this->categoryFromRequest($request)), $this->fileName($filters, 'xlsx'));
    }

    public function pdf(Request $request)
    {
        $filters = $this->filtersFromRequest($request);
        $content = MarginPdf::render($filters, company()->id, $this->categoryFromRequest($request));

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->fileName($filters, 'pdf') . '"',
            'Content-Length' => strlen($content),
        ]);
    }

    /** Period filters; compares with the previous period unless the URL says otherwise (like the dashboard). */
    protected function filtersFromRequest(Request $request): ReportFilters
    {
        $filters = ReportFilters::fromRequest($request);

        if (!$request->has('compare')) {
            $filters->compare = 'previous';
        }

        return $filters;
    }

    protected function categoryFromRequest(Request $request): ?int
    {
        $id = $request->query('category_id');

        return is_numeric($id) && (int)$id > 0 ? (int)$id : null;
    }

    /** @return array<string, mixed> query string of the exports / links (the comparison is always explicit) */
    protected function queryOf(ReportFilters $filters, ?int $categoryId): array
    {
        $query = $filters->toQuery();
        $query['compare'] = $filters->compare;
        unset($query['periodicity']);

        if ($categoryId) {
            $query['category_id'] = $categoryId;
        }

        return $query;
    }

    protected function fileName(ReportFilters $f, string $ext): string
    {
        return __('databackd-gaps-margins.export.file') . '-' . $f->from->format('Ymd') . '-' . $f->to->format('Ymd') . '.' . $ext;
    }

}
