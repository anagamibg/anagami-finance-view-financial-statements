<?php

namespace App\Http\Requests\Databackd\Analytics;

use App\Http\Requests\CoreRequest;

/**
 * Validation for the editable part of a system ratio: the target range and its direction.
 */
class UpdateSystemRatioRequest extends CoreRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'target_min' => 'nullable|numeric|between:-9999999,9999999',
            'target_max' => 'nullable|numeric|between:-9999999,9999999',
            'higher_is_better' => 'nullable',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $errors = $validator->errors();

            if ($this->filled('target_min') && $this->filled('target_max')
                && !$errors->has('target_min') && !$errors->has('target_max')
                && (float)$this->input('target_min') > (float)$this->input('target_max')) {
                $validator->errors()->add('target_max', __('databackd-analytics-builder.validation.target_range'));
            }
        });
    }

    public function messages()
    {
        return [
            'target_min.numeric' => __('databackd-analytics-builder.validation.numeric'),
            'target_max.numeric' => __('databackd-analytics-builder.validation.numeric'),
            'target_min.between' => __('databackd-analytics-builder.validation.numeric'),
            'target_max.between' => __('databackd-analytics-builder.validation.numeric'),
        ];
    }

    public function attributes()
    {
        return [
            'target_min' => __('databackd-analytics-builder.attributes.target_min'),
            'target_max' => __('databackd-analytics-builder.attributes.target_max'),
        ];
    }

}
