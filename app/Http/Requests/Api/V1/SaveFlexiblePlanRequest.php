<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;

class SaveFlexiblePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Visit::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'client_plan_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'account_id' => ['required', 'integer', 'min:1'], 'date' => ['required', 'date_format:Y-m-d'],
            'patch_id' => ['nullable', 'integer', 'min:1'], 'notes' => ['nullable', 'string', 'max:2000'], 'status' => ['required', 'in:active,cancelled'],
        ];
    }

    public function messages(): array
    {
        return ['expected_version.required' => 'Refresh the intention before editing.'];
    }
}
