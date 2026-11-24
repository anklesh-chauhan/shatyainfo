<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeleteCompaniesRequest extends FormRequest
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
            'ids' => ['required', 'array', 'list', 'min:1', $this->routeIs('companies.address-order') ? 'max:500' : 'max:50'],
            'ids.*' => ['required', 'distinct', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['ids.*.distinct' => 'Select each record only once.'];
    }
}
