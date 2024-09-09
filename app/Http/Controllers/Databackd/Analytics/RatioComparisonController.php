<?php

namespace App\Http\Controllers\Databackd\Analytics;

use App\Exports\Databackd\Analytics\RatiosExport;
use App\Http\Controllers\AccountBaseController;
use Illuminate\Http\Request;

/**
 * Comparison of the financial ratios of the legal entities (and of the whole group) for a period.
 */
class RatioComparisonController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-analytics-ratio-detail.comparison_title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $period = RatiosExport::resolvePeriod($request->query());
        $export = new RatiosExport('comparison', $period['from'], $period['to'], [], null, $period['label']);
        $matrix = $export->comparison();

        $this->period = $period;
        $this->periodOptions = RatiosExport::periodOptions($period['type'] === 'custom' ? 'month' : $period['type']);
        $this->entities = $matrix['entities'];
        $this->groups = $matrix['groups'];
        $this->summary = $matrix['summary'];
        $this->ratioCount = collect($matrix['groups'])->sum(fn($rows) => count($rows));
        $this->exportUrl = route('databackd.analytics.ratio-detail.export', array_filter([
            'mode' => 'comparison',
            'period_type' => $period['type'] === 'custom' ? null : $period['type'],
            'period' => $period['type'] === 'custom' ? null : $period['key'],
            'from' => $period['type'] === 'custom' ? $period['from']->toDateString() : null,
            'to' => $period['type'] === 'custom' ? $period['to']->toDateString() : null,
        ]));
        $this->dashboardUrl = \Route::has('databackd.analytics.ratios.index')
            ? route('databackd.analytics.ratios.index', array_filter([
                'period_type' => $period['type'] === 'custom' ? null : $period['type'],
                'period' => $period['type'] === 'custom' ? null : $period['key'],
            ]))
            : null;
        $this->builderUrl = null;

        return view('databackd.analytics.comparison.index', $this->data);
    }

}
