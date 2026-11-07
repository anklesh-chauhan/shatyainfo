<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;

class OpenDirectVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Visit::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'client_call_id' => ['required', 'uuid'],
            'company_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'flexible_plan_entry_id' => ['nullable', 'integer', 'min:1', 'required_with:flexible_plan_version'],
            'flexible_plan_version' => ['nullable', 'integer', 'min:1', 'required_with:flexible_plan_entry_id'],
            'unplanned_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['client_call_id.uuid' => 'Use a valid call identifier.'];
    }
}
