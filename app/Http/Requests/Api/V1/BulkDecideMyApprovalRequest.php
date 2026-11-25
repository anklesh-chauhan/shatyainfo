<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;

class BulkDecideMyApprovalRequest extends DecideMyApprovalRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'steps' => ['required', 'array', 'min:1', 'max:100'],
            'steps.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'steps.max' => 'Select up to 100 approvals at a time.'];
    }
}
