<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Tenancy\TenantDatabaseProvisioner;
use Illuminate\Console\Command;
use Throwable;

class ProvisionTenantDatabaseUsersCommand extends Command
{
    protected $signature = 'tenants:provision-database-users';

    protected $description = 'Create a dedicated MySQL user for each tenant that still shares the application account.';

    public function handle(TenantDatabaseProvisioner $databases): int
    {
        $exit = self::SUCCESS;

        Tenant::query()->whereNull('db_username')->orderBy('id')->each(function (Tenant $tenant) use ($databases, &$exit): void {
            try {
                $databases->createDedicatedUser($tenant);
                $this->components->info("Created a database user for {$tenant->domain}.");
            } catch (Throwable $exception) {
                $exit = self::FAILURE;
                $this->components->error($tenant->domain.': '.$exception->getMessage());
            }
        });

        return $exit;
    }
}
