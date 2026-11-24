<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class OverdueFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canAccessBusinessPage('View:OverdueFollowUps');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'search.max' => 'Search can contain up to 100 characters.',
            'until.after_or_equal' => 'Choose an end date on or after the start date.',
        ];
    }
}
