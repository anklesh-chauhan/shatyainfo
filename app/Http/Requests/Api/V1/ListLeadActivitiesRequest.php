<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListLeadActivitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(['ViewAny:LeadActivity', 'View:LeadActivity']) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'search.max' => 'Search can contain up to 100 characters.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
