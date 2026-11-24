<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveRegisterExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'in:personal,team'],
            'sales_dcr_id' => ['required', 'integer', 'min:1'],
            'expense_type_id' => ['required', 'integer', 'min:1'],
            'transport_mode_id' => ['nullable', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'between:0,99999999.99'],
            'quantity' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'rate' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,99999999.99'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'revision' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'string', 'size:64'],
            'is_auto_calculated' => ['prohibited'],
            'meta' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'deleted_by' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['revision.required' => 'Reload the expense before saving.', '*.decimal' => 'Use up to two decimal places.'];
    }
}
