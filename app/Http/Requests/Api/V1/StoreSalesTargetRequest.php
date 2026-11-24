<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSalesTargetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('Manage:SalesTargets') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $identity = $this->isMethod('PATCH') ? [
            'territory_id' => ['prohibited'],
            'period_type' => ['prohibited'],
            'starts_on' => ['prohibited'],
            'ends_on' => ['prohibited'],
            'revision' => ['required', 'integer', 'min:0'],
        ] : [
            'territory_id' => ['required', 'integer', 'min:1'],
            'period_type' => ['required', 'in:monthly,quarterly,annual'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
        ];

        return [
            ...$identity,
            'target_value_lakhs' => ['required', 'regex:/^\d{1,8}(\.\d{1,7})?$/'],
            'target_quantity' => ['required', 'numeric', 'between:0.000001,999999999999'],
            'note' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'revision.required' => 'Refresh this target before revising it.',
            'starts_on.date_format' => 'Choose a valid starting month.',
            'target_value_lakhs.regex' => 'Use up to eight whole digits and seven decimal places for the value in lakhs.',
            'target_quantity.between' => 'Enter a positive target in base units up to 999,999,999,999.',
            'note.required' => 'Enter a planning or revision reason.',
        ];
    }
}
