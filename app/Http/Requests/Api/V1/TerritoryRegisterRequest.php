<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TerritoryStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TerritoryRegisterRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TerritoryStatus::class)],
            'trash' => ['nullable', Rule::in(['current', 'all', 'trashed'])],
            'parent_territory_id' => ['nullable', 'integer', 'min:1'],
            'type_master_id' => ['nullable', 'integer', 'min:1'],
            'territory_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        return ['trash.in' => 'Choose current, all, or trashed territories.'];
    }
}
