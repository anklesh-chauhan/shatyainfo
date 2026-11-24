<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MonthlyTourPlanRegisterActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:submit,delete'],
            'scope' => ['required', 'in:personal,team'],
            'plans' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'plans.*' => ['array:id,revision'],
            'plans.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'plans.*.revision' => ['required', 'string', 'size:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'plans.*.id.distinct' => 'Select each tour plan only once.',
            'plans.*.revision.required' => 'Refresh the selected plans before continuing.',
        ];
    }
}
