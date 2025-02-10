<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Group of legal entities that are consolidated together.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $base_currency
 * @property string $method full|proportional
 * @property bool $eliminate_intercompany
 * @property bool $minority_interest
 * @property string|null $description
 */
class FinConsolidationGroup extends BaseModel
{

    use HasCompany;

    const METHODS = ['full', 'proportional'];

    protected $table = 'fin_consolidation_groups';
    protected $guarded = ['id'];
    protected $casts = ['eliminate_intercompany' => 'boolean', 'minority_interest' => 'boolean'];

    public function groupEntities(): HasMany
    {
        return $this->hasMany(FinConsolidationGroupEntity::class, 'group_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(FinConsolidationRule::class, 'group_id')->orderBy('sort')->orderBy('id');
    }

}
