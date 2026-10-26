<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile_number' => ['required', 'string', 'regex:/^(?:\+?91)?[6-9][0-9]{9}$/'],
            'otp' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile_number.regex' => 'Enter a valid Indian mobile number.',
            'otp.regex' => 'Enter the OTP sent to your mobile number.',
        ];
    }
}
