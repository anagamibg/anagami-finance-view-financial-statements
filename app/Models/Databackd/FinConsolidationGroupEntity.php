<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of a legal entity in a consolidation group.
 *
 * @property int $id
 * @property int $company_id
 * @property int $group_id
 * @property int $entity_id
 * @property float|null $ownership_pct overrides fin_entities.ownership_pct when set
 * @property float|null $fx_rate multiplier to the group currency, null = 1
 * @property bool $include
 */
class FinConsolidationGroupEntity extends BaseModel
{

    use HasCompany;

    protected $table = 'fin_consolidation_group_entities';
    protected $guarded = ['id'];
    protected $casts = ['ownership_pct' => 'float', 'fx_rate' => 'float', 'include' => 'boolean'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FinConsolidationGroup::class, 'group_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(FinEntity::class, 'entity_id');
    }

}
