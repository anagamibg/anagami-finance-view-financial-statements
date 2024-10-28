<?php

namespace App\Http\Requests\Databackd\Analytics;

use App\Http\Requests\CoreRequest;
use App\Models\Databackd\FinRatio;
use App\Services\Databackd\Analytics\FormulaEngine;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a custom financial ratio. The formula is checked with FormulaEngine::validate().
 */
class StoreRatioRequest extends CoreRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:191',
            'group' => ['required', Rule::in(FinRatio::GROUPS)],
            'format' => ['required', Rule::in(FinRatio::FORMATS)],
            'formula' => 'required|string|max:' . FormulaEngine::MAX_LENGTH,
            'target_min' => 'nullable|numeric|between:-9999999,9999999',
            'target_max' => 'nullable|numeric|between:-9999999,9999999',
            'higher_is_better' => 'nullable',
            'is_active' => 'nullable',
            'description' => 'nullable|string|max:1000',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $errors = $validator->errors();

            if (!$errors->has('formula') && $this->filled('formula')) {
                $problems = (new FormulaEngine(company()->id))->validate((string)$this->input('formula'), $this->selfCode());

                if ($problems) {
                    $validator->errors()->add('formula', implode(' ', $problems));
                }
            }

            if ($this->filled('target_min') && $this->filled('target_max')
                && !$errors->has('target_min') && !$errors->has('target_max')
                && (float)$this->input('target_min') > (float)$this->input('target_max')) {
                $validator->errors()->add('target_max', __('databackd-analytics-builder.validation.target_range'));
            }
        });
    }

    /** Code of the ratio that is being edited (null when creating) - used to reject self references. */
    protected function selfCode(): ?string
    {
        return null;
    }

    public function messages()
    {
        return [
            'target_min.numeric' => __('databackd-analytics-builder.validation.numeric'),
            'target_max.numeric' => __('databackd-analytics-builder.validation.numeric'),
            'target_min.between' => __('databackd-analytics-builder.validation.numeric'),
            'target_max.between' => __('databackd-analytics-builder.validation.numeric'),
            'formula.required' => __('databackd-analytics-builder.validation.formula_required'),
        ];
    }

    public function attributes()
    {
        return [
            'name' => __('databackd-analytics-builder.attributes.name'),
            'group' => __('databackd-analytics-builder.attributes.group'),
            'format' => __('databackd-analytics-builder.attributes.format'),
            'formula' => __('databackd-analytics-builder.attributes.formula'),
            'target_min' => __('databackd-analytics-builder.attributes.target_min'),
            'target_max' => __('databackd-analytics-builder.attributes.target_max'),
            'description' => __('databackd-analytics-builder.attributes.description'),
        ];
    }

}
