<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MonthlyTourPlanActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'string', 'size:64'],
            'comments' => [$this->is('*/reject') ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['comments.required' => 'Explain why this plan is being rejected.', 'revision.required' => 'Reload the plan before continuing.'];
    }
}
