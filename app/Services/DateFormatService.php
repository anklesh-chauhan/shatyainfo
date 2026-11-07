<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DateFormatService
{
    public const DEFAULT_FORMAT = 'd/m/Y';

    public const FORMATS = [
        'd/m/Y' => 'DD/MM/YYYY (07/11/2026)',
        'd-m-Y' => 'DD-MM-YYYY (07-11-2026)',
        'm/d/Y' => 'MM/DD/YYYY (11/07/2026)',
        'Y-m-d' => 'YYYY-MM-DD (2026-11-07)',
        'd M Y' => 'DD Mon YYYY (07 Nov 2026)',
        'd F Y' => 'DD Month YYYY (07 November 2026)',
    ];

    private array $formats = [];

    public function storageKey(): string
    {
        return 'date_display:'.(Tenant::current()?->getKey() ?? 'standalone');
    }

    public function dateFormat(): string
    {
        $key = IntegrationSetting::query()->getConnection()->getDatabaseName().':'.$this->storageKey();

        return $this->formats[$key] ??= $this->readFormat();
    }

    private function readFormat(): string
    {
        if (! Schema::hasTable('integration_settings')) {
            return self::DEFAULT_FORMAT;
        }

        $format = IntegrationSetting::query()->where('provider', $this->storageKey())->first()?->credential('date_format');

        return array_key_exists($format ?? '', self::FORMATS) ? $format : self::DEFAULT_FORMAT;
    }

    public function dateTimeFormat(): string
    {
        return $this->dateFormat().' H:i:s';
    }

    public function save(string $format): void
    {
        Validator::make(['date_format' => $format], [
            'date_format' => ['required', Rule::in(array_keys(self::FORMATS))],
        ])->validate();

        IntegrationSetting::query()->updateOrCreate(
            ['provider' => $this->storageKey()],
            ['is_enabled' => true, 'credentials' => ['date_format' => $format]],
        );
        $this->formats = [];
    }
}
