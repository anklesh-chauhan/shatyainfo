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
        return [
            'territory_id' => ['required', 'integer', 'exists:territories,id'],
            'period_type' => ['required', 'in:monthly,quarterly,annual'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'target_value_lakhs' => ['required', 'regex:/^\d{1,8}(\.\d{1,7})?$/'],
            'target_quantity' => ['required', 'numeric', 'between:0.000001,999999999999'],
            'note' => ['required', 'string', 'max:2000'],
        ];
    }
}
