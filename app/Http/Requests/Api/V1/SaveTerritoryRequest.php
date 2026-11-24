<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TerritoryStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTerritoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$required, 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::enum(TerritoryStatus::class)],
            'description' => ['nullable', 'string', 'max:10000'],
            'parent_territory_id' => ['nullable', 'integer', 'min:1'],
            'type_master_id' => ['nullable', 'integer', 'min:1'],
            'region_id' => ['nullable', 'integer', 'min:1'],
            'division_ids' => ['sometimes', 'array', 'list'],
            'division_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'position_ids' => ['sometimes', 'array', 'list'],
            'position_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'city_pin_code_ids' => [...$required, 'array', 'list', 'min:1'],
            'city_pin_code_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return ['city_pin_code_ids.required' => 'Select at least one city or area.'];
    }
}
