<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePushTokenRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:4096'],
            'target_type' => ['sometimes', Rule::in(['token', 'fid'])],
            'platform' => ['required', Rule::in(['web', 'android', 'ios', 'macos'])],
        ];
    }

    public function messages(): array
    {
        return ['token.required' => 'A push notification token is required.', 'platform.in' => 'Select a supported notification platform.'];
    }
}
