<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SecondarySalesRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'stockist_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'territory_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'reviewer_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'period' => ['sometimes', 'required', 'date_format:Y-m'],
        ];
    }
}
