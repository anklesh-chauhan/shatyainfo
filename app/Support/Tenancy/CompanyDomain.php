<?php

namespace App\Support\Tenancy;

class CompanyDomain
{
    public static function base(): string
    {
        $appHost = LandlordDomains::normalize((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($appHost !== '' && (str_ends_with($appHost, '.test') || in_array($appHost, ['localhost', '127.0.0.1'], true))) {
            return $appHost;
        }

        $configured = LandlordDomains::normalize((string) config('multitenancy.base_domain'));

        return $configured !== '' ? $configured : 'iterp.in';
    }

    public static function fromSlug(string $slug): string
    {
        return LandlordDomains::normalize($slug).'.'.self::base();
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
