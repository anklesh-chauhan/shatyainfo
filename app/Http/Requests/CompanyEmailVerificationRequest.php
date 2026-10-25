<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompanyEmailVerificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Enter the six-digit code from your email.',
            'code.regex' => 'Enter a valid six-digit code.',
        ];
    }
}
