<?php

namespace App\Http\Controllers\Databackd\Analytics;

use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinConsolidationGroup;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Models\Databackd\FinRatio;
use App\Services\Databackd\Analytics\ConsolidationService;
use App\Services\Databackd\Analytics\RatioCalculator;
use App\Services\Databackd\Analytics\SystemRatios;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Financial ratios dashboard: cards per ratio group with the value for the selected period,
 * a traffic light against the target range, the change versus the previous period and a 12-month sparkline.
 *
 * Query parameters: period_type=month|quarter|year, period=2026-10|2026-Q4|2026, entity=all|<entity id>|group:<id>
 * (group:<id> shows the ratios of the consolidated data of a consolidation group).
 */
class RatioDashboardController extends AccountBaseController
{

    const PERIOD_TYPES = ['month', 'quarter', 'year'];

    /** Number of months drawn in the sparkline. */
    const SPARK_MONTHS = 12;

    const GROUP_ICONS = [
        'liquidity' => 'tint',
        'profitability' => 'chart-line',
        'leverage' => 'balance-scale',
        'efficiency' => 'tachometer-alt',
        'custom' => 'sliders-h',
    ];

    protected RatioCalculator $calculator;

    /** First and last date with accounting entries of the company. */
    protected Carbon $dataFrom;
    protected Carbon $dataTo;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-analytics-ratios.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        // One calculator (and therefore one FormulaEngine value cache) for the whole request
        $this->calculator = new RatioCalculator(null, company()->id);

        $this->ratiosInstalled = $this->ensureRatios();
        $ratios = FinRatio::where('is_active', true)->orderBy('sort')->orderBy('id')->get();

        $this->resolveDataBounds();

        // Filters
        $periodType = $request->input('period_type');

        if (!in_array($periodType, self::PERIOD_TYPES, true)) {
            $periodType = 'month';
        }

        $periodKey = $request->input('period');

        // Detail screens link back with from/to
        if (!$periodKey && $request->filled('from')) {
            try {
                $periodKey = Carbon::parse($request->input('from'))->format('Y-m');
                $periodType = 'month';
            } catch (\Throwable $e) {
                $periodKey = null;
            }
        }

        $periodOptions = $this->periodOptions($periodType);

        if (!$periodKey || !isset($periodOptions[$periodKey])) {
            $periodKey = array_key_first($periodOptions);
        }

        [$fullFrom, $fullTo] = $this->periodBounds($periodType, $periodKey);
        $from = $fullFrom->copy()->max($this->dataFrom);
        $to = $fullTo->copy()->min($this->dataTo);
        [$prevFullFrom, $prevFullTo] = RatioCalculator::previousPeriod($fullFrom, $fullTo);
        $hasPrevious = $prevFullTo->gte($this->dataFrom);
        $prevFrom = $prevFullFrom->copy()->max($this->dataFrom);
        $prevTo = $prevFullTo->copy()->min($this->dataTo);

        $entities = FinEntity::where('is_active', true)->orderBy('sort')->orderBy('name')->get();
        $entityKey = (string)$request->input('entity', 'all');
        $filters = [];
        $resolver = null;
        $consolidationGroups = FinConsolidationGroup::orderBy('name')->get(['id', 'name']);
        $group = null;

        if (ctype_digit($entityKey) && $entities->contains('id', (int)$entityKey)) {
            $filters['entity_ids'] = [(int)$entityKey];
        }
        elseif (str_starts_with($entityKey, 'group:') && ($group = $consolidationGroups->firstWhere('id', (int)substr($entityKey, 6)))) {
            $entityKey = 'group:' . $group->id;
            $resolver = (new ConsolidationService())->ratioResolver($group, $this->calculator->engine());
        }
        else {
            $entityKey = 'all';
        }

        // Cards
        $sparkStart = $to->copy()->startOfMonth()->subMonthsNoOverflow(self::SPARK_MONTHS - 1)->max($this->dataFrom->copy()->startOfMonth());
        $cards = [];

        foreach ($ratios as $ratio) {
            $cards[$ratio->group][] = $this->buildCard($ratio, $from, $to, $hasPrevious ? [$prevFrom, $prevTo] : null, $sparkStart, $filters, $resolver, $entityKey);
        }

        $groups = [];

        foreach (FinRatio::GROUPS as $key) {
            if (!empty($cards[$key])) {
                $groups[$key] = $cards[$key];
            }
        }

        $allCards = collect($groups)->flatten(1);
        $this->groups = $groups;
        $this->groupIcons = self::GROUP_ICONS;
        $this->summary = [
            'total' => $allCards->count(),
            'good' => $allCards->where('status', 'good')->count(),
            'warning' => $allCards->where('status', 'warning')->count(),
            'bad' => $allCards->where('status', 'bad')->count(),
        ];
        $this->hasValues = $allCards->contains(fn($c) => $c['value'] !== null);

        $this->periodTypes = self::PERIOD_TYPES;
        $this->periodType = $periodType;
        $this->periodKey = $periodKey;
        $this->periodOptions = $periodOptions;
        $this->entities = $entities;
        $this->entityKey = $entityKey;
        $this->entityName = $group ? __('databackd-analytics-common.consolidated') . ': ' . $group->name : ($entityKey === 'all' ? null : $entities->firstWhere('id', (int)$entityKey)->name);
        $this->consolidationGroups = $consolidationGroups->map(fn($g) => ['id' => $g->id, 'name' => $g->name])->all();

        $this->from = $from;
        $this->to = $to;
        $this->prevLabel = $hasPrevious ? $this->periodLabel($periodType, $prevFullFrom) : null;
        $this->dateFormat = company()->date_format;

        $query = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'entity' => $entityKey];
        $this->exportUrl = $this->safeRoute('databackd.analytics.ratio-detail.export', $query);
        $this->comparisonUrl = $this->safeRoute('databackd.analytics.comparison.index', $query);
        $this->builderUrl = $this->safeRoute('databackd.analytics.builder.index');
        $this->resetUrl = route('databackd.analytics.ratios.index');

        return view('databackd.analytics.ratios.index', $this->data);
    }

    /**
     * Installs the missing system ratios and brings the locked part of the existing ones (formula, description) up to
     * date with SystemRatios::DEFINITIONS (e.g. the POS() guard against a negative equity). Both are idempotent and cheap.
     *
     * @return bool whether ratios were created for a company that had none (shows the "installed" notice)
     */
    protected function ensureRatios(): bool
    {
        try {
            $hadNone = FinRatio::count() === 0;
            $created = SystemRatios::installForCompanyId(company()->id);
            SystemRatios::syncForCompanyId(company()->id);

            return $hadNone && $created > 0;
        } catch (\Throwable $e) {
            Log::warning('Could not install system ratios: ' . $e->getMessage());

            return false;
        }
    }

    protected function resolveDataBounds(): void
    {
        $row = FinEntry::query()->selectRaw('MIN(entry_date) as min_date, MAX(entry_date) as max_date')->first();

        if ($row && $row->min_date) {
            $this->dataFrom = Carbon::parse($row->min_date)->startOfDay();
            $this->dataTo = Carbon::parse($row->max_date)->startOfDay();
        }
        else {
            $this->dataTo = now()->startOfDay();
            $this->dataFrom = $this->dataTo->copy()->subMonths(17)->startOfMonth();
        }

        // Never offer periods that start in the future
        if ($this->dataTo->gt(now()->startOfDay()->endOfMonth())) {
            $this->dataTo = now()->startOfDay()->endOfMonth()->startOfDay();
        }
    }

    /**
     * Selectable periods of the type, newest first: key => label.
     *
     * @return array<string, string>
     */
    protected function periodOptions(string $type): array
    {
        $options = [];
        $cursor = $this->dataTo->copy();

        while ($cursor->gte($this->dataFrom->copy()->startOfMonth())) {
            if ($type === 'year') {
                $key = $cursor->format('Y');
                $next = $cursor->copy()->startOfYear()->subDay();
            }
            elseif ($type === 'quarter') {
                $key = $cursor->format('Y') . '-Q' . $cursor->quarter;
                $next = $cursor->copy()->startOfQuarter()->subDay();
            }
            else {
                $key = $cursor->format('Y-m');
                $next = $cursor->copy()->startOfMonth()->subDay();
            }

            $options[$key] = $this->periodLabel($type, $cursor);
            $cursor = $next;
        }

        return $options;
    }

    protected function periodLabel(string $type, Carbon $date): string
    {
        if ($type === 'year') {
            return __('databackd-analytics-ratios.year_label', ['year' => $date->year]);
        }

        if ($type === 'quarter') {
            return __('databackd-analytics-ratios.quarter_label', ['q' => ['I', 'II', 'III', 'IV'][$date->quarter - 1], 'year' => $date->year]);
        }

        return __('databackd-analytics-ratios.months.' . $date->month) . ' ' . $date->year;
    }

    /**
     * Full calendar bounds of the period key.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodBounds(string $type, string $key): array
    {
        if ($type === 'year') {
            $d = Carbon::create((int)$key, 1, 1)->startOfDay();

            return [$d->copy()->startOfYear(), $d->copy()->endOfYear()->startOfDay()];
        }

        if ($type === 'quarter') {
            [$year, $q] = explode('-Q', $key);
            $d = Carbon::create((int)$year, ((int)$q - 1) * 3 + 1, 1)->startOfDay();

            return [$d->copy(), $d->copy()->endOfQuarter()->startOfDay()];
        }

        $d = Carbon::createFromFormat('Y-m-d', $key . '-01')->startOfDay();

        return [$d->copy(), $d->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * @param array{0: Carbon, 1: Carbon}|null $previous
     */
    protected function buildCard(FinRatio $ratio, Carbon $from, Carbon $to, ?array $previous, Carbon $sparkStart, array $filters, ?callable $resolver = null, string $entityKey = 'all'): array
    {
        $calc = $this->calculator;
        $known = [];

        // Monthly series for the sparkline; the monthly values are reused for the current / previous period when they coincide
        $series = $calc->series($ratio, $sparkStart, $to, 'month', $filters, $resolver);

        foreach ($series as $p) {
            $known[$p['from'] . '|' . $p['to']] = $p['value'];
        }

        $valueOf = function (Carbon $f, Carbon $t) use ($calc, $ratio, $filters, $resolver, &$known) {
            $k = $f->toDateString() . '|' . $t->toDateString();

            return $known[$k] ??= $calc->calculate($ratio, $f, $t, $filters, $resolver);
        };

        $value = $valueOf($from, $to);
        $prevValue = $previous ? $valueOf($previous[0], $previous[1]) : null;
        $read = $ratio->readText($value);

        return [
            'ratio' => $ratio,
            'value' => $value,
            'formatted' => RatioCalculator::format($ratio, $value),
            'status' => $calc->status($ratio, $value),
            'status_label' => $calc->statusLabel($ratio, $value),
            'read' => $read,
            'previous' => $prevValue,
            'previous_formatted' => $previous ? RatioCalculator::format($ratio, $prevValue) : null,
            'delta' => $this->delta($ratio, $value, $prevValue),
            'spark' => $this->sparkline($ratio, $series),
            'target' => $this->targetText($ratio),
            'tooltip' => $this->tooltip($ratio, $read),
            'url' => $this->detailUrl($ratio, $from, $to, $entityKey),
        ];
    }

    /**
     * Change versus the previous period: percentage points for percent, days for days, relative % for currency,
     * plain difference for ratios. The tone depends on the direction that is favourable for the ratio.
     */
    protected function delta(FinRatio $ratio, ?float $value, ?float $previous): ?array
    {
        if ($value === null || $previous === null) {
            return null;
        }

        $diff = $value - $previous;

        if ($ratio->format === 'currency') {
            $number = abs($previous) > 1e-9 ? $diff / abs($previous) * 100 : null;
            $unit = '%';
            $decimals = 1;
        }
        elseif ($ratio->format === 'percent') {
            $number = $diff;
            $unit = __('databackd-analytics-ratios.change_pp');
            $decimals = 1;
        }
        elseif ($ratio->format === 'days') {
            $number = $diff;
            $unit = __('databackd-analytics-ratios.change_days');
            $decimals = 0;
        }
        else {
            $number = $diff;
            $unit = '';
            $decimals = 2;
        }

        if ($number === null) {
            return null;
        }

        $rounded = round($number, $decimals);

        if ($rounded == 0) {
            return ['direction' => 'flat', 'tone' => 'neutral', 'text' => '0' . ($unit !== '' ? RatioCalculator::NBSP . $unit : '')];
        }

        $favourable = ($rounded > 0) === (bool)$ratio->higher_is_better;

        return [
            'direction' => $rounded > 0 ? 'up' : 'down',
            'tone' => $favourable ? 'good' : 'bad',
            'text' => ($rounded > 0 ? '+' : '') . RatioCalculator::number($rounded, $decimals) . ($unit !== '' ? RatioCalculator::NBSP . $unit : ''),
        ];
    }

    protected function targetText(FinRatio $ratio): string
    {
        $fmt = fn($v) => RatioCalculator::format($ratio, $v);
        $min = $ratio->target_min;
        $max = $ratio->target_max;

        if ($min !== null && $max !== null) {
            return __('databackd-analytics-ratios.target_range', ['min' => $fmt($min), 'max' => $fmt($max)]);
        }

        if ($min !== null) {
            return __('databackd-analytics-ratios.target_min', ['min' => $fmt($min)]);
        }

        if ($max !== null) {
            return __('databackd-analytics-ratios.target_max', ['max' => $fmt($max)]);
        }

        return __('databackd-analytics-ratios.no_target');
    }

    /**
     * HTML for the Bootstrap tooltip (every piece is escaped here): what the ratio measures in plain words, how to read
     * the current value, the target and the direction. The formula itself is on the detail screen.
     */
    protected function tooltip(FinRatio $ratio, ?string $read): string
    {
        $html = '<div class="text-left"><strong>' . e($ratio->name) . '</strong>';

        if ($ratio->description) {
            $html .= '<div class="mt-1">' . e($ratio->description) . '</div>';
        }

        if ($read) {
            $html .= '<div class="mt-1"><span class="text-lightest">' . e(__('databackd-analytics-ratios.how_to_read')) . ':</span> ' . e($read) . '</div>';
        }

        $html .= '<div class="mt-1">' . e(__('databackd-analytics-ratios.target')) . ': ' . e($this->targetText($ratio)) . ' &middot; '
            . e($ratio->higher_is_better ? __('databackd-analytics-ratios.higher_better') : __('databackd-analytics-ratios.lower_better')) . '</div>';
        $html .= '<div class="mt-1 text-lightest">' . e(__('databackd-analytics-ratios.click_for_detail')) . '</div></div>';

        return $html;
    }

    /**
     * Geometry of the sparkline in a 100 x 30 viewBox (the target band and the points).
     *
     * @param array<int, array{label: string, from: string, to: string, value: ?float}> $series
     */
    protected function sparkline(FinRatio $ratio, array $series): ?array
    {
        $values = array_values(array_filter(array_column($series, 'value'), fn($v) => $v !== null));

        if (count($values) < 2) {
            return null;
        }

        $w = 100;
        $h = 30;
        $pad = 3;
        $min = min($values);
        $max = max($values);

        if ($max - $min < 1e-9) {
            $min -= 1;
            $max += 1;
        }

        $margin = ($max - $min) * 0.12;
        $min -= $margin;
        $max += $margin;
        $y = fn($v) => round($pad + ($max - $v) / ($max - $min) * ($h - 2 * $pad), 2);
        $count = count($series);
        $points = [];

        foreach ($series as $i => $p) {
            if ($p['value'] === null) {
                continue;
            }

            $points[] = [
                'x' => round($pad + ($count > 1 ? $i / ($count - 1) : 0) * ($w - 2 * $pad), 2),
                'y' => $y($p['value']),
                'label' => $p['label'],
                'text' => RatioCalculator::format($ratio, $p['value']),
            ];
        }

        $band = null;

        if ($ratio->target_min !== null || $ratio->target_max !== null) {
            $top = $ratio->target_max !== null ? min($max, $ratio->target_max) : $max;
            $bottom = $ratio->target_min !== null ? max($min, $ratio->target_min) : $min;

            if ($top > $bottom) {
                $band = ['y' => $y($top), 'h' => round($y($bottom) - $y($top), 2)];
            }
        }

        return [
            'w' => $w,
            'h' => $h,
            'line' => implode(' ', array_map(fn($p) => $p['x'] . ',' . $p['y'], $points)),
            'points' => $points,
            'last' => end($points),
            'band' => $band,
        ];
    }

    /** Link to the detail with the same period and the same entity filter (a consolidation group included). */
    protected function detailUrl(FinRatio $ratio, Carbon $from, Carbon $to, string $entityKey): ?string
    {
        return $this->safeRoute('databackd.analytics.ratio-detail.show', [
            'ratio' => $ratio->id, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'entity' => $entityKey,
        ]);
    }

    /**
     * URL of a route owned by another package, or null when it is missing or needs other parameters.
     */
    protected function safeRoute(string $name, array $params = []): ?string
    {
        if (!Route::has($name)) {
            return null;
        }

        try {
            return route($name, $params);
        } catch (\Throwable $e) {
            return null;
        }
    }

}
