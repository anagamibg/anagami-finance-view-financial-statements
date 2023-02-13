<?php

namespace App\Http\Controllers\Databackd\Reports;

use App\Exports\Databackd\Reports\PnlExport;
use App\Exports\Databackd\Reports\PnlPdf;
use App\Http\Controllers\AccountBaseController;
use App\Services\Databackd\Reports\PnlStatement;
use App\Services\Databackd\Reports\ReportFilters;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class PnlReportController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-reports-pnl.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $this->pageTitle = __('databackd-reports-pnl.title');
        $this->filters = ReportFilters::fromRequest($request);
        $this->statement = (new PnlStatement(company()->id))->build($this->filters);
        $this->query = $this->filters->toQuery();
        // audit 2026-10-30 (G11): the entries screen belongs to databackd_data
        $this->entriesUrl = \Route::has('databackd.data.entries.index') && databackd_module('databackd_data') ? route('databackd.data.entries.index') : null;
        $this->currency = company()->currency->currency_code ?? 'EUR';
        $this->currencySymbol = company()->currency->currency_symbol ?? '€';

        return view('databackd.reports.pnl.index', $this->data);
    }

    public function excel(Request $request)
    {
        $filters = ReportFilters::fromRequest($request);

        return Excel::download(new PnlExport($filters, company()->id), $this->fileName($filters, 'xlsx'));
    }

    public function pdf(Request $request)
    {
        $filters = ReportFilters::fromRequest($request);
        $content = PnlPdf::render($filters, company()->id);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->fileName($filters, 'pdf') . '"',
            'Content-Length' => strlen($content),
        ]);
    }

    protected function fileName(ReportFilters $f, string $ext): string
    {
        return __('databackd-reports-pnl.export.file') . '-' . $f->from->format('Ymd') . '-' . $f->to->format('Ymd') . '.' . $ext;
    }

}
