<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consolidation rule. `params` by type:
 *   eliminate_counterparty  {accounts: string[] (optional codes, default all P&L + 411/401)}
 *   eliminate_account_pair  {pairs: [[codeA, codeB], ...]}  e.g. [["411","401"],["703","602"]]
 *   reclassify              {from: code, to: code}
 *   exclude_account         {accounts: string[]}
 *
 * @property int $id
 * @property int $company_id
 * @property int $group_id
 * @property string $type
 * @property array|null $params
 * @property string|null $description
 * @property int $sort
 */
class FinConsolidationRule extends BaseModel
{

    use HasCompany;

    const TYPES = ['eliminate_counterparty', 'eliminate_account_pair', 'reclassify', 'exclude_account'];

    protected $table = 'fin_consolidation_rules';
    protected $guarded = ['id'];
    protected $casts = ['params' => 'array'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FinConsolidationGroup::class, 'group_id');
    }

}
