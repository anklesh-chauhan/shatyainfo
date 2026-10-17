<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CompanyDomain;
use App\Support\Tenancy\RegistrationCaptcha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CompanyRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => strtolower(trim((string) $this->input('slug')))]);
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
