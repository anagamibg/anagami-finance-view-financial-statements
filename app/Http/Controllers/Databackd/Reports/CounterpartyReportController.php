<?php

namespace App\Http\Controllers\Databackd\Reports;

use App\Exports\Databackd\Reports\CounterpartyData;
use App\Exports\Databackd\Reports\CounterpartyExport;
use App\Exports\Databackd\Reports\CounterpartyPdf;
use App\Http\Controllers\AccountBaseController;
use App\Services\Databackd\Reports\ReportFilters;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Counterparties report: turnover, documents, share and trend per counterparty (clients / suppliers),
 * a monthly detail per counterparty and Excel / PDF exports.
 */
class CounterpartyReportController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-reports-counterparties.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $this->filters = ReportFilters::fromRequest($request);
        $this->side = CounterpartyData::normalizeSide($request->query('side'));
        $this->query = array_merge($this->filters->toQuery(), ['side' => $this->side]);
        $this->report = (new CounterpartyData(company()->id))->listing($this->filters, $this->side);
        $this->generatedAt = now();

        return view('databackd.reports.counterparties.index', $this->data);
    }

    public function show(Request $request)
    {
        $filters = ReportFilters::fromRequest($request);
        $side = CounterpartyData::normalizeSide($request->query('side'));
        $name = trim((string)$request->query('name', ''));

        if ($name === '') {
            return redirect()->route('databackd.reports.counterparties.index', array_merge($filters->toQuery(), ['side' => $side]));
        }

        $this->filters = $filters;
        $this->side = $side;
        $this->query = array_merge($filters->toQuery(), ['side' => $side]);
        $this->detail = (new CounterpartyData(company()->id))->detail($filters, $side, $name);
        $this->pageTitle = $this->detail['label'];
        $this->generatedAt = now();

        return view('databackd.reports.counterparties.show', $this->data);
    }

    public function excel(Request $request)
    {
        $filters = ReportFilters::fromRequest($request);
        $side = CounterpartyData::normalizeSide($request->query('side'));

        return Excel::download(new CounterpartyExport($filters, company()->id, $side), 'counterparties-' . $side . '-' . now()->format('Y-m-d') . '.xlsx');
    }

    public function pdf(Request $request)
    {
        $filters = ReportFilters::fromRequest($request);
        $side = CounterpartyData::normalizeSide($request->query('side'));
        $content = CounterpartyPdf::render($filters, company()->id, $side);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="counterparties-' . $side . '-' . now()->format('Y-m-d') . '.pdf"',
        ]);
    }

}
