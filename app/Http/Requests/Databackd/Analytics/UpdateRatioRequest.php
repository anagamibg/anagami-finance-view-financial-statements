<?php

namespace App\Http\Requests\Databackd\Analytics;

use App\Models\Databackd\FinRatio;

/**
 * Validation for editing a custom ratio. The route parameter {ratio} holds the id; the code never changes,
 * it is only used to reject self references and reference cycles in the formula.
 */
class UpdateRatioRequest extends StoreRatioRequest
{

    protected function selfCode(): ?string
    {
        return FinRatio::where('company_id', company()->id)->whereKey($this->route('ratio'))->value('code');
    }

}
