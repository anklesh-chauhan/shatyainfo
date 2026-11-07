<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class FieldCoverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['month' => ['sometimes', 'date_format:Y-m']];
    }

    public function messages(): array
    {
        return ['month.date_format' => 'Choose a month in YYYY-MM format.'];
    }
}
