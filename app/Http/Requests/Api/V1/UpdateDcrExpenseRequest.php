<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDcrExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expense_type_id' => ['sometimes', 'integer', 'exists:expense_types,id'],
            'transport_mode_id' => ['nullable', 'integer', 'exists:transport_modes,id'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
