<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DeleteLeadActivitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(['ViewAny:LeadActivity', 'View:LeadActivity']) ?? false;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one activity.',
            'ids.max' => 'Select at most 50 activities.',
            'ids.*.distinct' => 'Select each activity only once.',
        ];
    }
}
