<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

class TenantBranding
{
    public static function name(): string
    {
        return Tenant::current()?->name ?: config('app.name', 'Shatya Info');
    }

    public static function logo(): ?string
    {
        $tenant = Tenant::current();
        if (! $tenant || ! $tenant->accountIsConfigured()) {
            return null;
        }

        $logo = Organization::query()->orderBy('id')->value('logo');

        return $logo ? Storage::disk('public')->url($logo) : null;
    }
}
