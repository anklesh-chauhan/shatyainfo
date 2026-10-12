<?php

namespace App\Support\Tenancy;

class LandlordDomains
{
    public static function isLandlordHost(string $host): bool
    {
        $host = self::normalize($host);

        if ($host === '') {
            return false;
        }

        return in_array($host, self::hosts(), true);
    }

    /**
     * @return list<string>
     */
    public static function hosts(): array
    {
        $configured = config('multitenancy.landlord_domains', []);
        $hosts = is_array($configured) ? $configured : [];

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = $appHost;
        }

        $hosts = array_merge($hosts, ['localhost', '127.0.0.1', '[::1]']);

        return array_values(array_unique(array_filter(array_map(
            fn ($host) => self::normalize((string) $host),
            $hosts,
        ))));
    }

    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];

        return $host;
    }
}
