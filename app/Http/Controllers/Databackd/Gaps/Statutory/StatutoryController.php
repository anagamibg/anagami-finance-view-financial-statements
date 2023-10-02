<?php

namespace App\Http\Controllers\Databackd\Gaps\Statutory;

use App\Exports\Databackd\Gaps\Statutory\StatutoryDocument;
use App\Exports\Databackd\Gaps\Statutory\StatutoryExport;
use App\Exports\Databackd\Gaps\Statutory\StatutoryPdf;
use App\Http\Controllers\AccountBaseController;
use App\Services\Databackd\Gaps\Statutory\StatutoryReport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Annual financial statements by NSS (simplified): balance sheet and income statement for a legal entity or a
 * consolidation group, with print, PDF and Excel. All figures come from StatutoryReport.
 *
 * Query string of every action: ?scope=entity:<id>|group:<id>&year=<YYYY>[&tab=balance|income]
 */
class StatutoryController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-gaps-statutory.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $this->pageTitle = __('databackd-gaps-statutory.title');
        [$report, $year, $scope] = $this->resolve($request);

        $document = StatutoryDocument::prepare($year, $scope, company()->id);

        $this->doc = $document;
        $this->year = $year;

        $this->scopes = $report->scopes();
        $this->scopeValue = $report->scopeValue($scope);
        $years = $report->years();

        if (!in_array($year, $years, true)) {
            $years[] = $year;
            rsort($years);
        }

        $this->years = $years;

        $this->activeTab = $request->query('tab') === 'income' ? 'income' : 'balance';
        $this->query = ['scope' => $this->scopeValue, 'year' => $year];
        $this->hasScope = !empty($this->scopes['entities']) || !empty($this->scopes['groups']);
        $this->hasData = $this->hasAmounts($document['balance']) || $this->hasAmounts($document['income']);
        $this->kpis = $this->kpis($document);
        $this->currency = $document['info']['currency'];
        $this->currencySymbol = ['EUR' => '€', 'BGN' => 'лв.', 'USD' => '$', 'GBP' => '£'][$this->currency] ?? $this->currency;

        return view('databackd.gaps.statutory.index', $this->data);
    }

    public function excel(Request $request)
    {
        [, $year, $scope] = $this->resolve($request);

        return Excel::download(new StatutoryExport($year, $scope, company()->id), StatutoryDocument::fileName($year, $scope, 'xlsx'));
    }

    public function pdf(Request $request)
    {
        [, $year, $scope] = $this->resolve($request);
        $content = StatutoryPdf::render($year, $scope, company()->id);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . StatutoryDocument::fileName($year, $scope, 'pdf') . '"',
            'Content-Length' => strlen($content),
        ]);
    }

    public function print(Request $request)
    {
        [, $year, $scope] = $this->resolve($request);

        return view('databackd.gaps.statutory.print', StatutoryDocument::prepare($year, $scope, company()->id));
    }

    /**
     * @return array{0: StatutoryReport, 1: int, 2: array} report service, year (default: the last year with data), scope
     */
    protected function resolve(Request $request): array
    {
        $report = new StatutoryReport(company()->id, databackd_module('databackd_analytics')); // audit 2026-10-30 (G11)
        $scope = $report->parseScope($request->query('scope'));
        $year = (int)$request->query('year');

        if ($year < 2000 || $year > 2100) {
            $year = $report->years()[0];
        }

        return [$report, $year, $scope];
    }

    /** Cards above the statements: total assets, equity, net sales, profit after tax - with the change vs the previous year. */
    protected function kpis(array $document): array
    {
        $balance = collect($document['balance']['rows'])->keyBy('key');
        $income = collect($document['income']['rows'])->keyBy('key');
        $card = function (string $key, $row) {
            $current = (float)($row['current'] ?? 0);
            $previous = (float)($row['previous'] ?? 0);

            return [
                'key' => $key, 'value' => $current, 'previous' => $previous,
                'change_pct' => abs($previous) >= 0.005 ? round(($current - $previous) / abs($previous) * 100, 1) : null,
            ];
        };

        return [
            $card('total_assets', $balance['asset.total'] ?? null),
            $card('equity', $balance['liab.A'] ?? null),
            $card('net_sales', $income['B.1'] ?? null),
            $card('net_profit', $income['C.5'] ?? null),
        ];
    }

    protected function hasAmounts(array $statement): bool
    {
        foreach ($statement['rows'] as $row) {
            if ($row['type'] !== 'heading' && (abs((float)$row['current']) >= 0.005 || abs((float)$row['previous']) >= 0.005)) {
                return true;
            }
        }

        return false;
    }

}
