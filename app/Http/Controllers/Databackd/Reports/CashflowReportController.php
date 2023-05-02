<?php

namespace App\Http\Controllers\Databackd\Reports;

use App\Exports\Databackd\Reports\CashflowExport;
use App\Exports\Databackd\Reports\CashflowPdf;
use App\Http\Controllers\AccountBaseController;
use App\Services\Databackd\Reports\CashflowStatement;
use App\Services\Databackd\Reports\ReportFilters;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CashflowReportController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-reports-cashflow.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $this->filters = ReportFilters::fromRequest($request);
        $this->statement = (new CashflowStatement(company()->id))->build($this->filters);
        $this->query = $this->filters->toQuery();
        $this->excelUrl = route('databackd.reports.cashflow.excel', $this->query);
        $this->pdfUrl = route('databackd.reports.cashflow.pdf', $this->query);

        return view('databackd.reports.cashflow.index', $this->data);
    }

    public function excel(Request $request)
    {
        $filters = ReportFilters::fromRequest($request);

        return Excel::download(new CashflowExport($filters, company()->id), $this->fileName($filters, 'xlsx'));
    }

    public function pdf(Request $request)
    {
        $filters = ReportFilters::fromRequest($request);

        return response(CashflowPdf::render($filters, company()->id), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->fileName($filters, 'pdf') . '"',
        ]);
    }

    protected function fileName(ReportFilters $filters, string $extension): string
    {
        return 'cashflow-' . $filters->from->format('Ymd') . '-' . $filters->to->format('Ymd') . '.' . $extension;
    }

}
