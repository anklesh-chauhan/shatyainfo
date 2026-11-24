<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListRegisterExpensesRequest extends FormRequest
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
            'register' => ['sometimes', 'boolean'],
            'scope' => ['sometimes', 'in:personal,team'],
            'trash' => ['sometimes', 'in:active,with,only'],
            'origin' => ['sometimes', 'in:manual,calculated'],
            'kind' => [$this->routeIs('dcr-expenses.options') ? 'required' : 'sometimes', 'in:reports,types,modes'],
            'search' => ['sometimes', 'string', 'max:100'],
            'sales_dcr_id' => [$this->routeIs('dcr-expenses.recalculate') ? 'required' : 'sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['sales_dcr_id.required' => 'Select a daily call report.'];
    }
}
