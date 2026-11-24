<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;

class DeleteLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Lead::class) ?? false;
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
            'ids.required' => 'Select at least one lead.',
            'ids.max' => 'Select at most 50 leads.',
            'ids.*.distinct' => 'Select each lead only once.',
        ];
    }
}
