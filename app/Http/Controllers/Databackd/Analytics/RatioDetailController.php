<?php

namespace App\Http\Controllers\Databackd\Analytics;

use App\Exports\Databackd\Analytics\RatiosExport;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinRatio;
use App\Services\Databackd\Analytics\RatioCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Detail of one financial ratio (value, 18-month trend with the target corridor, formula components)
 * and the Excel export of the ratio screens.
 */
class RatioDetailController extends AccountBaseController
{

    /** Months in the trend chart. */
    const CHART_MONTHS = 18;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-analytics-ratio-detail.detail_title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function show(Request $request, $ratio)
    {
        $ratio = FinRatio::findOrFail($ratio);
        $calc = new RatioCalculator(null, company()->id);

        $period = RatiosExport::resolvePeriod($request->query());
        $entity = RatiosExport::resolveEntity($request->query('entity'));
        $filters = $entity['ids'] ? ['entity_ids' => $entity['ids']] : [];
        $resolver = RatiosExport::groupResolver($entity['group'], $calc);

        // Value and formula components for the selected period
        $explain = $calc->engine()->explain($ratio->formula, $period['from'], $period['to'], $filters, $resolver);
        $value = $explain['value'];
        $status = $calc->status($ratio, $value);

        // Previous period of the same length (the whole period, not the clipped one)
        [$prevFrom, $prevTo] = RatioCalculator::previousPeriod($period['from'], $period['full_to']);
        $previous = $calc->calculate($ratio, $prevFrom, $prevTo, $filters, $resolver);

        // Trend: 18 calendar months ending in the current month (the selected period is highlighted in the chart)
        $chartTo = Carbon::today()->max($period['to']);
        $chartFrom = $chartTo->copy()->startOfMonth()->subMonthsNoOverflow(self::CHART_MONTHS - 1);
        $series = $calc->series($ratio, $chartFrom, $chartTo, 'month', $filters, $resolver);

        $this->ratio = $ratio;
        $this->period = $period;
        $this->entityFilter = $entity;
        $this->value = $value;
        $this->status = $status;
        $this->statusLabel = $calc->statusLabel($ratio, $value);
        $this->readText = $ratio->readText($value);
        $this->nullReason = $value === null && !$explain['error'] ? $this->nullReason($explain['variables'] ?? []) : null;
        $this->valueText = RatioCalculator::format($ratio, $value);
        $this->previousValue = $previous;
        $this->previousText = RatioCalculator::format($ratio, $previous);
        $this->previousPeriodLabel = $prevFrom->translatedFormat(company()->date_format) . ' – ' . $prevTo->translatedFormat(company()->date_format);
        $this->change = $this->changeInfo($ratio, $value, $previous);
        $this->explain = $explain;
        $this->components = $this->componentRows($explain['variables'] ?? [], $ratio);
        $this->calculation = $this->substitute($ratio->formula, $explain['variables'] ?? [], $ratio);
        $this->chart = $this->chartData($ratio, $series, $calc, $period);
        $this->entityRows = $this->entityRows($ratio, $calc, $period);
        $this->targetText = $this->targetText($ratio);
        $this->entities = FinEntity::where('is_active', 1)->orderBy('sort')->orderBy('name')->get(['id', 'name']);
        $this->consolidationGroups = RatiosExport::consolidationGroups();
        $this->periodOptions = RatiosExport::periodOptions($period['type'] === 'custom' ? 'month' : $period['type']);
        $this->pageTitle = $ratio->name;
        $this->dashboardUrl = \Route::has('databackd.analytics.ratios.index')
            ? route('databackd.analytics.ratios.index', array_filter([
                'period_type' => $period['type'] === 'custom' ? null : $period['type'],
                'period' => $period['type'] === 'custom' ? null : $period['key'],
                'from' => $period['type'] === 'custom' ? $period['from']->toDateString() : null,
                'to' => $period['type'] === 'custom' ? $period['to']->toDateString() : null,
                'entity' => $entity['value'] === 'all' ? null : $entity['value'],
            ]))
            : null;
        $this->comparisonUrl = route('databackd.analytics.comparison.index', array_filter([
            'period_type' => $period['type'] === 'custom' ? null : $period['type'],
            'period' => $period['type'] === 'custom' ? null : $period['key'],
        ]));

        return view('databackd.analytics.ratio-detail.show', $this->data);
    }

    /**
     * Excel export of the ratios: ?mode=dashboard|comparison&period_type=&period=&from=&to=&entity=
     */
    public function export(Request $request)
    {
        $export = RatiosExport::fromRequest($request);

        return Excel::download($export, $export->fileName());
    }

    /**
     * @return array{direction: string, text: string}|null direction good|bad|flat in terms of the ratio's higher_is_better
     */
    protected function changeInfo(FinRatio $ratio, ?float $value, ?float $previous): ?array
    {
        if ($value === null || $previous === null) {
            return null;
        }

        $decimals = $ratio->format === 'days' ? 0 : ($ratio->format === 'percent' ? 1 : 2);
        $diff = round($value - $previous, $decimals);
        $text = RatioCalculator::formatChange($ratio, $value, $previous);

        if ($diff == 0) {
            return ['direction' => 'flat', 'arrow' => 'minus', 'text' => $text];
        }

        $favourable = $ratio->higher_is_better ? $diff > 0 : $diff < 0;

        return ['direction' => $favourable ? 'good' : 'bad', 'arrow' => $diff > 0 ? 'arrow-up' : 'arrow-down', 'text' => $text];
    }

    protected function targetText(FinRatio $ratio): ?string
    {
        $t = fn(string $key, array $r = []) => __('databackd-analytics-ratio-detail.detail.' . $key, $r);
        $fmt = fn($v) => RatioCalculator::format($ratio, $v);

        if ($ratio->target_min !== null && $ratio->target_max !== null) {
            return $t('target_both', ['min' => $fmt($ratio->target_min), 'max' => $fmt($ratio->target_max)]);
        }

        if ($ratio->target_min !== null) {
            return $t('target_min_only', ['min' => $fmt($ratio->target_min)]);
        }

        if ($ratio->target_max !== null) {
            return $t('target_max_only', ['max' => $fmt($ratio->target_max)]);
        }

        return null;
    }

    /**
     * Why the value is missing, in plain words: the components that are 0 (a division by zero, usually no data for the
     * period) or negative (POS() refuses e.g. a negative equity).
     */
    protected function nullReason(array $variables): string
    {
        $zero = [];
        $negative = [];

        foreach ($variables as $v) {
            if ($v['value'] === null) {
                continue;
            }

            if (abs($v['value']) < 0.005) {
                $zero[] = $v['label'];
            } elseif ($v['value'] < 0) {
                $negative[] = $v['label'];
            }
        }

        $t = fn(string $key, array $r = []) => __('databackd-analytics-ratio-detail.detail.' . $key, $r);
        $parts = [];

        if ($zero) {
            $parts[] = $t('null_zero', ['items' => implode('; ', $zero)]);
        }

        if ($negative) {
            $parts[] = $t('null_negative', ['items' => implode('; ', $negative)]);
        }

        return $parts ? implode(' ', $parts) : $t('calc_error');
    }

    /**
     * Rows of the "formula components" table.
     *
     * @return array<int, array{key: string, label: string, value: ?float, text: string}>
     */
    protected function componentRows(array $variables, FinRatio $ratio): array
    {
        $refs = FinRatio::whereIn('code', collect($variables)->where('kind', 'REF')->pluck('arg')->all() ?: [''])->get()->keyBy('code');
        $rows = [];

        foreach ($variables as $v) {
            if ($v['kind'] === 'REF' && isset($refs[$v['arg']])) {
                $text = RatioCalculator::format($refs[$v['arg']], $v['value']);
            } else {
                $text = $v['value'] === null ? '—' : RatioCalculator::number($v['value'], 2);
            }

            $rows[] = ['key' => $v['key'], 'label' => $v['label'], 'value' => $v['value'], 'text' => $text, 'is_ref' => $v['kind'] === 'REF'];
        }

        return $rows;
    }

    /**
     * The formula with the variable keys replaced by their values (only when every variable is found as written).
     */
    protected function substitute(string $formula, array $variables, FinRatio $ratio): ?string
    {
        if (!$variables) {
            return null;
        }

        $keys = collect($variables)->sortByDesc(fn($v) => strlen($v['key']))->values();
        $out = $formula;

        foreach ($keys as $v) {
            if (!str_contains($out, $v['key']) || $v['value'] === null) {
                return null;
            }

            $out = str_replace($v['key'], '(' . RatioCalculator::number($v['value'], 2) . ')', $out);
        }

        return $out;
    }

    /**
     * Data for the Chart.js trend chart.
     *
     * @param array<int, array{label: string, from: string, to: string, value: ?float}> $series
     */
    protected function chartData(FinRatio $ratio, array $series, RatioCalculator $calc, array $period): array
    {
        $labels = [];
        $values = [];
        $statuses = [];
        $selected = [];
        $texts = [];
        $months = __('databackd-analytics-ratio-detail.months_short');

        foreach ($series as $p) {
            $d = Carbon::parse($p['from']);
            $labels[] = ($months[$d->month] ?? $d->format('m')) . ' ' . $d->format('y');
            $values[] = $p['value'] === null ? null : round($p['value'], 4);
            $statuses[] = $calc->status($ratio, $p['value']);
            $texts[] = RatioCalculator::format($ratio, $p['value']);
            $selected[] = Carbon::parse($p['to'])->gte($period['from']) && Carbon::parse($p['from'])->lte($period['to']);
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'statuses' => $statuses,
            'texts' => $texts,
            'selected' => $selected,
            'target_min' => $ratio->target_min,
            'target_max' => $ratio->target_max,
            'format' => $ratio->format,
            'has_data' => count(array_filter($values, fn($v) => $v !== null)) > 1,
        ];
    }

    /**
     * Value of the ratio for every legal entity (and the group) in the selected period.
     *
     * @return array<int, array{id: ?int, name: string, value: ?float, text: string, status: string}>
     */
    protected function entityRows(FinRatio $ratio, RatioCalculator $calc, array $period): array
    {
        $entities = FinEntity::where('is_active', 1)->orderBy('sort')->orderBy('name')->get(['id', 'name']);
        $rows = [];

        foreach ($entities as $e) {
            $v = $calc->calculate($ratio, $period['from'], $period['to'], ['entity_ids' => [(int)$e->id]]);
            $rows[] = ['id' => (int)$e->id, 'name' => $e->name, 'value' => $v, 'text' => RatioCalculator::format($ratio, $v), 'status' => $calc->status($ratio, $v), 'status_label' => $calc->statusLabel($ratio, $v)];
        }

        if (count($rows) > 1) {
            $v = $calc->calculate($ratio, $period['from'], $period['to'], ['entity_ids' => $entities->pluck('id')->map(fn($i) => (int)$i)->all()]);
            $rows[] = ['id' => null, 'name' => __('databackd-analytics-ratio-detail.detail.group_all'), 'value' => $v, 'text' => RatioCalculator::format($ratio, $v), 'status' => $calc->status($ratio, $v), 'status_label' => $calc->statusLabel($ratio, $v)];
        }

        return $rows;
    }

}
