<?php

namespace App\Services\Databackd\Analytics;

use App\Models\Databackd\FinRatio;
use Carbon\Carbon;

/**
 * Calculates FinRatio values over periods and classifies them against the target range.
 */
class RatioCalculator
{

    /** Relative distance from the target bound (in the unfavourable direction) still shown as "warning". */
    const WARNING_TOLERANCE = 0.15;

    protected FormulaEngine $engine;

    public function __construct(?FormulaEngine $engine = null, ?int $companyId = null)
    {
        $this->engine = $engine ?? new FormulaEngine($companyId);
    }

    public function engine(): FormulaEngine
    {
        return $this->engine;
    }

    /**
     * Value of the ratio for the period (null when it cannot be calculated, e.g. division by zero).
     * Percent ratios are returned as a percentage number (12.5 = 12.5%) when the formula multiplies by 100;
     * the calculator never rescales the formula result.
     *
     * @param array $filters FinancialDataService filters (entity_ids, region_id, ...)
     * @param callable|null $resolver see FormulaEngine, used for consolidated data
     */
    public function calculate(FinRatio $r, Carbon $from, Carbon $to, array $filters = [], ?callable $resolver = null): ?float
    {
        try {
            return $this->engine->evaluate($r->formula, $from, $to, $filters, $resolver);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * Value for each sub-period of [$from, $to].
     *
     * @param string $periodicity month|quarter|year
     * @return array<int, array{label: string, from: string, to: string, value: ?float}>
     */
    public function series(FinRatio $r, Carbon $from, Carbon $to, string $periodicity, array $filters = [], ?callable $resolver = null): array
    {
        $out = [];

        foreach (self::periods($from, $to, $periodicity) as $p) {
            $out[] = $p + ['value' => $this->calculate($r, Carbon::parse($p['from']), Carbon::parse($p['to']), $filters, $resolver)];
        }

        return $out;
    }

    /**
     * Calendar periods covering [$from, $to] (first and last are clipped to the range).
     *
     * @return array<int, array{label: string, from: string, to: string}>
     */
    public static function periods(Carbon $from, Carbon $to, string $periodicity): array
    {
        $out = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            if ($periodicity === 'year') {
                $periodEnd = $cursor->copy()->endOfYear();
                $label = $cursor->format('Y');
            } elseif ($periodicity === 'quarter') {
                $periodEnd = $cursor->copy()->endOfQuarter();
                $label = $cursor->format('Y') . ' Q' . $cursor->quarter;
            } else {
                $periodEnd = $cursor->copy()->endOfMonth();
                $label = $cursor->format('Y-m');
            }

            $periodEnd = $periodEnd->startOfDay();
            $out[] = ['label' => $label, 'from' => $cursor->toDateString(), 'to' => $periodEnd->min($end)->toDateString()];
            $cursor = $periodEnd->copy()->addDay();
        }

        return $out;
    }

    /**
     * The same-length period immediately before [$from, $to] (month, quarter and year aligned periods keep their kind).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function previousPeriod(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        if ($from->isSameDay($from->copy()->startOfMonth()) && $to->isSameDay($to->copy()->endOfMonth()->startOfDay())) {
            $months = $from->diffInMonths($to->copy()->addDay());
            $months = max(1, (int)round($months));
            $prevFrom = $from->copy()->subMonthsNoOverflow($months)->startOfMonth();

            return [$prevFrom, $from->copy()->subDay()];
        }

        $days = $from->diffInDays($to) + 1;

        return [$from->copy()->subDays($days), $from->copy()->subDay()];
    }

    /**
     * good | warning | bad | none, according to target_min / target_max and higher_is_better.
     * Overshooting the target range in the favourable direction is "good".
     */
    public function status(FinRatio $r, ?float $value): string
    {
        if ($value === null || ($r->target_min === null && $r->target_max === null)) {
            return 'none';
        }

        $min = $r->target_min;
        $max = $r->target_max;

        $below = $min !== null && $value < $min;
        $above = $max !== null && $value > $max;

        if (!$below && !$above) {
            return 'good';
        }

        $favourable = ($below && !$r->higher_is_better) || ($above && $r->higher_is_better);

        if ($favourable) {
            return 'good';
        }

        $bound = $below ? $min : $max;
        $distance = abs($value - $bound) / max(abs($bound), 1e-9);

        return $distance <= self::WARNING_TOLERANCE ? 'warning' : 'bad';
    }

    /**
     * Where the value sits against the target range: in (inside the range), better (outside, in the favourable
     * direction), worse (outside, in the unfavourable direction) or none (no value or no target).
     * status() maps "in" and "better" to good; this tells the UI which of the two it is ("better than the target").
     */
    public function position(FinRatio $r, ?float $value): string
    {
        if ($value === null || ($r->target_min === null && $r->target_max === null)) {
            return 'none';
        }

        $below = $r->target_min !== null && $value < $r->target_min;
        $above = $r->target_max !== null && $value > $r->target_max;

        if (!$below && !$above) {
            return 'in';
        }

        return ($below && !$r->higher_is_better) || ($above && $r->higher_is_better) ? 'better' : 'worse';
    }

    /**
     * Text of the status for the UI: "better than the target" is told apart from "in the target range".
     */
    public function statusLabel(FinRatio $r, ?float $value): string
    {
        $status = $this->status($r, $value);

        if ($status === 'good' && $this->position($r, $value) === 'better') {
            return __('databackd-analytics-common.statuses.better');
        }

        return __('databackd-analytics-common.statuses.' . $status);
    }

    /**
     * Value formatted for the UI according to the ratio format and the locale:
     * bg - ratio 1,85, percent 12,4 %, days 45 дни, currency 1 234,00; en - 1.85, 12.4 %, 45 days, 1,234.00.
     */
    public static function format(FinRatio $r, ?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        switch ($r->format) {
            case 'percent':
                return self::number($value, 1) . self::NBSP . '%';
            case 'days':
                return self::number($value, 0) . self::NBSP . __('databackd-analytics-common.days_unit');
            default: // ratio, currency
                return self::number($value, 2);
        }
    }

    /**
     * Change between two values of the ratio in its own unit: percentage points for percent, days for days,
     * a plain difference for ratio and currency. "+0,12", "−3,4 п.п.", "+5 дни"; null when either value is missing.
     */
    public static function formatChange(FinRatio $r, ?float $value, ?float $previous): ?string
    {
        if ($value === null || $previous === null) {
            return null;
        }

        $decimals = $r->format === 'days' ? 0 : ($r->format === 'percent' ? 1 : 2);
        $diff = round($value - $previous, $decimals);

        if ($diff == 0) {
            $text = self::number(0, $decimals);
        } else {
            $text = ($diff > 0 ? '+' : '') . self::number($diff, $decimals);
        }

        if ($r->format === 'percent') {
            return $text . self::NBSP . __('databackd-analytics-common.pp_unit');
        }

        return $r->format === 'days' ? $text . self::NBSP . __('databackd-analytics-common.days_unit') : $text;
    }

    /** Non-breaking space: keeps "12,4 %" and "1 234" on one line. */
    const NBSP = "\u{00A0}";

    /**
     * A number in the format of the current locale (bg: 1 234,56; en: 1,234.56) with a real minus sign "−".
     */
    public static function number(float $value, int $decimals = 2): string
    {
        $english = str_starts_with((string)app()->getLocale(), 'en');
        $rounded = round($value, $decimals);
        $text = number_format(abs($rounded), $decimals, $english ? '.' : ',', $english ? ',' : self::NBSP);

        return ($rounded < 0 ? '−' : '') . $text;
    }

}
