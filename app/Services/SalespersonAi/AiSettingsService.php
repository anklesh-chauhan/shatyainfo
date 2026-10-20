<?php

namespace App\Services\SalespersonAi;

use App\Models\IntegrationSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiSettingsService
{
    public function scope(): string
    {
        return (string) (Tenant::current()?->getKey() ?? 'standalone');
    }

    public function storageKey(): string
    {
        return 'salesperson_ai:'.$this->scope();
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $config = config('salesperson_ai');
        $scope = $this->scope();
        $allowed = $scope === 'standalone'
            ? (bool) $config['allow_standalone']
            : in_array($scope, array_map('strval', $config['allowed_tenants']), true);
        $config['enabled'] = $allowed && (bool) $config['enabled'];
        $config['provider'] = $config['tenant_providers'][$scope] ?? $config['provider'];
        if (! $allowed) {
            foreach (['openai', 'anthropic'] as $provider) {
                $config['providers'][$provider]['api_key'] = null;
            }
        }

        $stored = IntegrationSetting::query()->where('provider', $this->storageKey())->first();
        if ($stored !== null) {
            $config = array_replace_recursive($config, $stored->credentials ?? []);
            $config['enabled'] = $stored->is_enabled;
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function formState(): array
    {
        $config = $this->configuration();
        $state = [
            'enabled' => (bool) $config['enabled'],
            'provider' => $config['provider'],
            'daily_requests' => $config['daily_requests'],
            'user_requests_per_minute' => $config['user_requests_per_minute'],
            'timeout_seconds' => $config['timeout_seconds'],
            'max_output_tokens' => $config['max_output_tokens'],
            'ollama_base_url' => $config['providers']['ollama']['base_url'],
        ];
        foreach (['openai', 'anthropic', 'ollama'] as $provider) {
            $state[$provider.'_model'] = $config['providers'][$provider]['model'] ?? '';
        }
        foreach (['openai', 'anthropic'] as $provider) {
            $state[$provider.'_api_key'] = '';
            $state['clear_'.$provider.'_key'] = false;
            $state['has_'.$provider.'_key'] = filled($config['providers'][$provider]['api_key'] ?? null);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function prepare(array $state, bool $forTest = false): array
    {
        $rules = [
            'data.enabled' => ['required', 'boolean'],
            'data.provider' => ['required', Rule::in(['openai', 'anthropic', 'ollama'])],
            'data.daily_requests' => ['required', 'integer', 'min:1', 'max:100000'],
            'data.user_requests_per_minute' => ['required', 'integer', 'min:1', 'max:100'],
            'data.timeout_seconds' => ['required', 'integer', 'min:1', 'max:120'],
            'data.max_output_tokens' => ['required', 'integer', 'min:128', 'max:2048'],
            'data.ollama_base_url' => ['nullable', 'string', 'max:255'],
        ];
        foreach (['openai', 'anthropic', 'ollama'] as $provider) {
            $rules['data.'.$provider.'_model'] = ['nullable', 'string', 'max:150', 'regex:/^[a-zA-Z0-9._:\/-]+$/'];
        }
        foreach (['openai', 'anthropic'] as $provider) {
            $rules['data.'.$provider.'_api_key'] = ['nullable', 'string', 'max:1024', 'not_regex:/[\r\n]/'];
            $rules['data.clear_'.$provider.'_key'] = ['sometimes', 'boolean'];
        }
        $data = Validator::make(['data' => $state], $rules)->validate()['data'];
        $config = $this->configuration();
        foreach (['enabled', 'provider', 'daily_requests', 'user_requests_per_minute', 'timeout_seconds', 'max_output_tokens'] as $field) {
            $config[$field] = $data[$field];
        }
        foreach (['openai', 'anthropic', 'ollama'] as $provider) {
            if (array_key_exists($provider.'_model', $data)) {
                $config['providers'][$provider]['model'] = trim((string) $data[$provider.'_model']);
            }
        }
        foreach (['openai', 'anthropic'] as $provider) {
            if ($data['clear_'.$provider.'_key'] ?? false) {
                $config['providers'][$provider]['api_key'] = null;
            }
            if (filled($data[$provider.'_api_key'] ?? null)) {
                $config['providers'][$provider]['api_key'] = trim($data[$provider.'_api_key']);
            }
        }
        if (array_key_exists('ollama_base_url', $data)) {
            $config['providers']['ollama']['base_url'] = rtrim(trim((string) $data['ollama_base_url']), '/');
        }
        $url = $config['providers']['ollama']['base_url'];
        if (filled($url) && ! $this->isLocalEndpoint($url)) {
            throw ValidationException::withMessages([
                'data.ollama_base_url' => 'Use an HTTP or HTTPS address with localhost or a private IPv4 address, without a path, query, or credentials.',
            ]);
        }

        $provider = $config['provider'];
        if ($config['enabled'] || $forTest) {
            if (blank($config['providers'][$provider]['model'] ?? null)) {
                throw ValidationException::withMessages(['data.'.$provider.'_model' => 'Enter a model name for the selected provider.']);
            }
            if ($provider === 'ollama' && blank($url)) {
                throw ValidationException::withMessages(['data.ollama_base_url' => 'Enter the Ollama server address.']);
            }
            if ($provider !== 'ollama' && blank($config['providers'][$provider]['api_key'] ?? null)) {
                throw ValidationException::withMessages(['data.'.$provider.'_api_key' => 'Enter an API key for the selected provider.']);
            }
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function save(array $state): void
    {
        $config = $this->prepare($state);
        $values = array_intersect_key($config, array_flip([
            'provider', 'providers', 'daily_requests', 'user_requests_per_minute', 'timeout_seconds', 'max_output_tokens',
        ]));
        IntegrationSetting::query()->updateOrCreate(
            ['provider' => $this->storageKey()],
            ['is_enabled' => (bool) $config['enabled'], 'credentials' => $values],
        );
        Log::info('salesperson_ai.settings_saved', [
            'scope' => $this->scope(),
            'user_id' => auth()->id(),
            'enabled' => (bool) $config['enabled'],
            'provider' => $config['provider'],
        ]);
    }

    public function isLocalEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return false;
        }
        $host = $parts['host'] ?? '';
        if (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return true;
        }
        if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $octets = array_map('intval', explode('.', $host));

        return $octets[0] === 10
            || ($octets[0] === 192 && $octets[1] === 168)
            || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31);
    }
}
