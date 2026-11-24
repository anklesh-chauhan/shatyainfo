<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavePatchRequest extends FormRequest
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
            'code' => [...$required, 'string', 'max:255'],
            'territory_id' => [...$required, 'integer', 'min:1'],
            'city_pin_code_id' => ['nullable', 'integer', 'min:1'],
            'color' => ['nullable', 'hex_color'],
            'description' => ['nullable', 'string', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return ['territory_id.required' => 'Choose a territory for this patch.'];
    }
}
