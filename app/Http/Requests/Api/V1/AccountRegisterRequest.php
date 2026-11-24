<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AccountRegisterRequest extends FormRequest
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
            'trash' => ['sometimes', 'in:active,with,only'],
            'specialty_id' => ['sometimes', 'integer', 'min:1'],
            'therapy_area_id' => ['sometimes', 'integer', 'min:1'],
            'account_status_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['trash.in' => 'Select active accounts, trash, or all accounts.'];
    }
}
