<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveCompanyRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $required = $this->routeIs('contacts.create-company') ? ['nullable'] : ($creating ? ['required'] : ['sometimes', 'required']);

        return [
            'name' => [...($creating ? [] : ['sometimes']), 'required', 'string', 'max:255'],
            'phone_number' => [...$required, 'string', 'max:255'],
            'email' => [...$required, 'email', 'max:255'],
            'secondary_email' => [...$required, 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'no_of_employees' => ['nullable', 'string', 'max:255'],
            'twitter' => ['nullable', 'string', 'max:255'],
            'linked_in' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'industry_type_id' => ['nullable', 'integer', 'min:1'],
            'account_master_id' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return ['secondary_email.required' => 'Enter the company’s secondary email address.'];
    }
}
