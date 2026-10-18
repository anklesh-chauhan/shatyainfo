<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use App\Support\Tenancy\CompanyDomain;
use App\Support\Tenancy\RegistrationCaptcha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CompanyRegistrationRequest extends FormRequest
{
    public function messages(): array
    {
        return [
            'mobile_number.required' => 'Enter your mobile number.',
            'mobile_number.regex' => 'Enter a valid mobile number with country code (for example +91 9876543210).',
            'mobile_number.unique' => 'This mobile number is already registered.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $mobile = $this->input('mobile_number');
        $mobile = is_string($mobile) ? preg_replace('/[\s().-]+/', '', $mobile) : '';
        if (preg_match('/^(?:\+91|0091|91|0)?([6-9][0-9]{9})$/', $mobile, $matches)) {
            $mobile = '+91'.$matches[1];
        }
        $this->merge(['slug' => strtolower(trim((string) $this->input('slug'))), 'mobile_number' => $mobile]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'company' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $result = CompanyDomain::checkSlug((string) $value);
                if (! $result['available']) {
                    $fail($result['message']);
                }
            }],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'mobile_number' => ['bail', 'required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/', Rule::unique(Tenant::class, 'registration_mobile')],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()],
        ];

        if (RegistrationCaptcha::usesTurnstile()) {
            $rules['cf-turnstile-response'] = ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! RegistrationCaptcha::verifyTurnstile(is_string($value) ? $value : null, $this->ip())) {
                    $fail('Please complete the CAPTCHA again.');
                }
            }];
        } else {
            $rules['captcha'] = ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $challenge = $this->session()->pull('registration_captcha');
                if (! is_array($challenge) || $challenge['expires'] < time() || ! hash_equals($challenge['answer'], strtoupper(trim((string) $value)))) {
                    $fail('Please complete the CAPTCHA again.');
                }
            }];
        }

        return $rules;
    }
}
