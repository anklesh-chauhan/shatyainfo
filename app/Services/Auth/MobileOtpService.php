<?php

namespace App\Services\Auth;

use App\Enums\IntegrationProvider;
use App\Models\Employee;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\Multitenancy\Models\Tenant;

class MobileOtpService
{
    public function request(string $input): void
    {
        $mobile = $this->mobile($input);
        $key = $this->key($mobile);

        if (RateLimiter::tooManyAttempts("{$key}:send", 3)) {
            throw ValidationException::withMessages(['mobile_number' => ['Please wait before requesting another OTP.']]);
        }

        RateLimiter::hit("{$key}:send", 300);
        Cache::forget($key);

        $user = $this->eligibleUser($mobile);
        if ($user === null) {
            return;
        }

        $this->configuredGateway();

        $response = Http::withHeaders(['authkey' => $this->apiKey()])
            ->timeout(10)
            ->post('https://control.msg91.com/api/v5/otp?'.http_build_query([
                'template_id' => $this->templateId(),
                'mobile' => $mobile,
            ]));

        if (! $response->successful() || $response->json('type') !== 'success') {
            abort(503, 'The OTP could not be sent. Please try again later.');
        }

        Cache::put($key, $user->id, now()->addMinutes(5));
        RateLimiter::clear("{$key}:verify");
    }

    public function verify(string $input, string $otp): ?User
    {
        $mobile = $this->mobile($input);
        $key = $this->key($mobile);

        if (RateLimiter::tooManyAttempts("{$key}:verify", 5)) {
            throw ValidationException::withMessages(['otp' => ['Too many attempts. Request a new OTP.']]);
        }

        RateLimiter::hit("{$key}:verify", 300);
        $userId = Cache::get($key);
        $user = $this->eligibleUser($mobile);

        if ($userId === null || $user === null || (int) $userId !== $user->id) {
            return null;
        }

        $this->configuredGateway();

        $response = Http::withHeaders(['authkey' => $this->apiKey()])
            ->timeout(10)
            ->get('https://control.msg91.com/api/v5/otp/verify', [
                'mobile' => $mobile,
                'otp' => $otp,
            ]);

        if (! $response->successful() || $response->json('type') !== 'success') {
            return null;
        }

        Cache::forget($key);
        RateLimiter::clear("{$key}:verify");

        return $user->load('employee');
    }

    private function eligibleUser(string $mobile): ?User
    {
        $local = substr($mobile, 2);
        $employees = Employee::query()
            ->whereIn('mobile_number', [$local, $mobile, '+'.$mobile])
            ->where('is_active', true)
            ->whereNotNull('login_id')
            ->limit(2)
            ->get();

        if ($employees->count() !== 1) {
            return null;
        }

        return User::query()->find($employees->first()->login_id);
    }

    private function mobile(string $input): string
    {
        return '91'.substr($input, -10);
    }

    private function key(string $mobile): string
    {
        return 'field-login-otp:'.(Tenant::current()?->getKey() ?? 'landlord').':'.$mobile;
    }

    private function configuredGateway(): void
    {
        $setting = IntegrationSetting::query()->where('provider', IntegrationProvider::Sms->value)->first();
        $provider = $setting?->credential('provider') ?? 'msg91';

        if ($provider !== 'msg91' || $this->apiKey() === null || $this->templateId() === null) {
            abort(503, 'Mobile OTP login is not configured.');
        }
    }

    private function apiKey(): ?string
    {
        return IntegrationSetting::resolve(IntegrationProvider::Sms, 'api_key');
    }

    private function templateId(): ?string
    {
        return IntegrationSetting::resolve(IntegrationProvider::Sms, 'otp_template_id');
    }
}
