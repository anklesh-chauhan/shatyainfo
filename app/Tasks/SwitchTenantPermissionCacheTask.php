<?php

namespace App\Tasks;

use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;
use Spatie\Permission\PermissionRegistrar;

class SwitchTenantPermissionCacheTask implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        $this->flush();
    }

    public function forgetCurrent(): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->clearPermissionsCollection();
        $registrar->forgetCachedPermissions();
    }
}
