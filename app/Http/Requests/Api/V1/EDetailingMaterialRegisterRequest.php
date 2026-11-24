<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EDetailingMaterialRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'publication' => ['sometimes', Rule::in(['published', 'unpublished'])],
        ];
    }

    public function messages(): array
    {
        return ['search.max' => 'Search using up to 100 characters.'];
    }
}
