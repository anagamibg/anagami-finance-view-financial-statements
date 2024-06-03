<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Services\Databackd\Analytics\RatioCalculator;
use App\Traits\HasCompany;
use Illuminate\Support\Facades\Lang;

/**
 * Financial ratio defined by a formula (see App\Services\Databackd\Analytics\FormulaEngine).
 *
 * The name and the description of a system ratio cannot be edited, so they are read from the language file
 * (databackd-analytics-ratios.system.<code>.name|description) - an English user sees English names; the stored text is
 * the fallback. Custom ratios show what the client typed.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string $group liquidity|profitability|leverage|efficiency|custom
 * @property string $formula
 * @property string $format ratio|percent|days|currency
 * @property float|null $target_min
 * @property float|null $target_max
 * @property bool $higher_is_better
 * @property string|null $description
 * @property bool $is_system
 * @property bool $is_active
 * @property int $sort
 */
class FinRatio extends BaseModel
{

    use HasCompany;

    const GROUPS = ['liquidity', 'profitability', 'leverage', 'efficiency', 'custom'];
    const FORMATS = ['ratio', 'percent', 'days', 'currency'];

    protected $table = 'fin_ratios';
    protected $guarded = ['id'];
    protected $casts = [
        'target_min' => 'float', 'target_max' => 'float',
        'higher_is_better' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean',
    ];

    public function getNameAttribute($value)
    {
        return $this->systemText('name') ?? $value;
    }

    public function getDescriptionAttribute($value)
    {
        return $this->systemText('description') ?? $value;
    }

    /**
     * One sentence in plain language that explains what the value means ("On every 1 € of short-term liabilities the
     * company has 1,60 € ..."), for people who are not accountants. Null for custom ratios and missing values.
     */
    public function readText(?float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $number = $this->format === 'percent' ? RatioCalculator::number($value, 1) : RatioCalculator::number($value, $this->format === 'days' ? 0 : 2);

        return $this->systemText('read', [
            'value' => RatioCalculator::format($this, $value),
            'number' => $number,
            'percent' => RatioCalculator::number($value * 100, 0) . RatioCalculator::NBSP . '%',
        ]);
    }

    protected function systemText(string $key, array $replace = []): ?string
    {
        $code = $this->attributes['code'] ?? null;

        if (empty($this->attributes['is_system']) || !$code) {
            return null;
        }

        $langKey = 'databackd-analytics-ratios.system.' . $code . '.' . $key;

        return Lang::has($langKey) ? __($langKey, $replace) : null;
    }

}
