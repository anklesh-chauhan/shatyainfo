<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ContactRegisterRequest extends FormRequest
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
            'company_id' => ['sometimes', 'integer', 'min:1'],
            'ids' => [$this->routeIs('contacts.delete') ? 'required' : 'prohibited', 'array', 'list', 'min:1', 'max:50'],
            'ids.*' => ['integer', 'distinct', 'min:1'],
            'name' => [$this->routeIs('contacts.create-option') ? 'required' : 'prohibited', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['ids.*.distinct' => 'Select each contact only once.'];
    }
}
