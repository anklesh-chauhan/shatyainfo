<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListTravelSegmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'kind' => [$this->routeIs('travel-segments.options') ? 'required' : 'sometimes', 'in:reports,visits,plan_details,patches,accounts,towns,modes'],
            'sales_dcr_id' => ['sometimes', 'integer', 'min:1'],
            'patch_id' => ['sometimes', 'integer', 'min:1'],
            'trash' => ['sometimes', 'in:active,with,only'],
            'origin' => ['sometimes', 'in:manual,derived,imported'],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['kind.in' => 'Select a supported travel field.', 'search.max' => 'Use at most 100 characters.'];
    }
}
