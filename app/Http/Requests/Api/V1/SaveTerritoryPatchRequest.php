<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveTerritoryPatchRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:100'],
            'city_pin_code_id' => ['nullable', 'integer', 'min:1'],
            'color' => ['nullable', 'hex_color'],
            'description' => ['nullable', 'string', 'max:10000'],
            'territory_id' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return ['territory_id.missing' => 'Edit the patch within its current territory.'];
    }
}
