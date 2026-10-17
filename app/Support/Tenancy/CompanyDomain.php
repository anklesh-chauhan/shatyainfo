<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;

class CompanyDomain
{
    public static function base(): string
    {
        $appHost = LandlordDomains::normalize((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($appHost !== '' && (str_ends_with($appHost, '.test') || in_array($appHost, ['localhost', '127.0.0.1'], true))) {
            return $appHost;
        }

        $configured = LandlordDomains::normalize((string) config('multitenancy.base_domain'));

        return $configured !== '' ? $configured : 'shatyainfo.com';
    }

    public static function fromSlug(string $slug): string
    {
        return LandlordDomains::normalize($slug).'.'.self::base();
    }

    /**
     * @return array{status: string, available: bool, message: string, domain: string|null}
     */
    public static function checkSlug(string $slug): array
    {
        $slug = LandlordDomains::normalize($slug);

        if (strlen($slug) < 3) {
            return self::slugResult('invalid', false, 'Use at least 3 characters.', null);
        }

        if (strlen($slug) > 32 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return self::slugResult('invalid', false, 'Use letters, numbers, and single hyphens.', null);
        }

        if (in_array($slug, self::reserved(), true)) {
            return self::slugResult('reserved', false, 'That address is reserved.', null);
        }

        $domain = self::fromSlug($slug);

        if (LandlordDomains::isLandlordHost($domain) || Tenant::query()->where('domain', $domain)->exists()) {
            return self::slugResult('taken', false, 'That company address is already in use.', $domain);
        }

        return self::slugResult('available', true, $domain.' is available.', $domain);
    }

    /**
     * @return array{status: string, available: bool, message: string, domain: string|null}
     */
    private static function slugResult(string $status, bool $available, string $message, ?string $domain): array
    {
        return ['status' => $status, 'available' => $available, 'message' => $message, 'domain' => $domain];
    }

    public static function resolve(string $input): string
    {
        $input = LandlordDomains::normalize($input);

        if ($input === '') {
            return '';
        }

        if (! str_contains($input, '.')) {
            return self::fromSlug($input);
        }

        return $input;
    }

    public static function loginUrl(string $domain): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $scheme.'://'.LandlordDomains::normalize($domain).'/admin/login';
    }

    /**
     * @return list<string>
     */
    public static function reserved(): array
    {
        return [
            'www', 'admin', 'api', 'app', 'mail', 'smtp', 'ftp', 'cdn',
            'static', 'assets', 'status', 'help', 'support', 'billing',
            'login', 'register', 'landlord', 'tenant', 'localhost',
        ];
    }
}
