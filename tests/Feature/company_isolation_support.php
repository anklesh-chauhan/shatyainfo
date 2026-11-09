<?php

use App\Models\Tenant;
use App\Tasks\CustomSwitchTenantDatabaseTask;
use App\Tasks\SwitchTenantPermissionCacheTask;

function selectIsolationCompany(array $company): void
{
    if (! preg_match('/^it_erp_testing_iso_[a-f0-9]{12}_[ab]$/', $company['database'])) {
        throw new RuntimeException('Unexpected isolation database.');
    }
    config(['multitenancy.switch_tenant_tasks' => [
        CustomSwitchTenantDatabaseTask::class, SwitchTenantPermissionCacheTask::class,
    ]]);
    $tenant = new Tenant;
    $tenant->forceFill(['id' => $company['id'], 'name' => $company['name'],
        'database' => $company['database'], 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    if (app('db')->connection()->getDatabaseName() !== $company['database']) {
        throw new RuntimeException('Company database did not switch.');
    }
}
