<?php

namespace App\Http\Requests\Api\V1;

use App\Models\FollowUp;
use Illuminate\Foundation\Http\FormRequest;

class DeleteFollowUpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', FollowUp::class) ?? false;
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
            'ids.required' => 'Select at least one follow-up.',
            'ids.max' => 'Select at most 50 follow-ups.',
            'ids.*.distinct' => 'Select each follow-up only once.',
        ];
    }
}
