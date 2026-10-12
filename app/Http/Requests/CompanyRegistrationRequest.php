<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use App\Support\Tenancy\CompanyDomain;
use App\Support\Tenancy\LandlordDomains;
use Illuminate\Foundation\Http\FormRequest;

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
        return [
            'captcha' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $challenge = $this->session()->pull('registration_captcha');
                if (! is_array($challenge) || $challenge['expires'] < time() || ! hash_equals($challenge['answer'], strtoupper(trim((string) $value)))) {
                    $fail('Please complete the CAPTCHA again.');
                }
            }],
            'company' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'min:3',
                'max:32',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (in_array($value, CompanyDomain::reserved(), true)) {
                        $fail('That address is reserved.');

                        return;
                    }

                    $domain = CompanyDomain::fromSlug((string) $value);

                    if (LandlordDomains::isLandlordHost($domain) || Tenant::query()->where('domain', $domain)->exists()) {
                        $fail('That company address is already in use.');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ];
    }
}
