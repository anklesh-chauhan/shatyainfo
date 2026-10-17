<?php

namespace App\Support\Tenancy;

use App\Jobs\ManageCloudflareCname;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class TenantProvisioner
{
    public function __construct(private TenantDatabaseProvisioner $databases) {}

    public function provision(Tenant $tenant, ?array $owner = null): void
    {
        if ($owner === null) {
            $owner = Tenant::$pendingOwner;
        }

        Tenant::$pendingOwner = null;

        $databaseCreated = false;

        try {
            $this->databases->createDatabase($tenant);
            $databaseCreated = true;
            $this->databases->createDedicatedUser($tenant);
            $tenant->forceFill(['provisioning_progress' => 10, 'provisioning_step' => 'Creating sign-in tables'])->saveQuietly();

            $tenant->makeCurrent();

            $this->migrateSchema(TenantSchemaMigrator::LOGIN_MIGRATIONS, function (int $completed, int $total) use ($tenant): void {
                $tenant->forceFill([
                    'provisioning_progress' => 10 + (int) floor(80 * $completed / max(1, $total)),
                    'provisioning_step' => 'Creating sign-in tables ('.$completed.' of '.$total.')',
                ])->saveQuietly();
            });
            $tenant->forceFill(['provisioning_progress' => 92, 'provisioning_step' => 'Creating your sign-in account'])->saveQuietly();

            $this->createOwner($owner);
            $organization = new OrganizationSeeder;
            $organization->setContainer(app());
            $organization->run();

            Log::info('Provisioned tenant database.', [
                'tenant_id' => $tenant->id,
                'domain' => $tenant->domain,
            ]);
        } catch (Throwable $exception) {
            Log::error('Tenant provisioning failed.', [
                'tenant_id' => $tenant->id,
                'message' => $exception->getMessage(),
            ]);

            if ($databaseCreated) {
                $this->cleanup($tenant);
            }

            throw $exception;
        } finally {
            Tenant::$pendingOwner = null;

            if ($tenant->isCurrent()) {
                $tenant->forgetCurrent();
            }
        }

        ManageCloudflareCname::dispatch(
            tenantId: $tenant->id,
            name: (string) $tenant->name,
            domain: (string) $tenant->domain,
            action: 'create',
            cloudflareRecordId: $tenant->cloudflare_record_id,
        );
    }

    /**
     * @param  array{name: string, email: string, password: string}|null  $owner
     */
    private function createOwner(?array $owner): void
    {
        if ($owner === null || $owner['email'] === '' || $owner['password'] === '') {
            return;
        }

        $user = new User;
        $user->forceFill([
            'name' => $owner['name'] !== '' ? $owner['name'] : $owner['email'],
            'email' => $owner['email'],
            'password' => $owner['password'],
            'email_verified_at' => $owner['email_verified_at'] ?? null,
        ])->save();

        foreach (['web', 'tenant'] as $guard) {
            $role = Role::on('tenant')->firstOrCreate([
                'name' => 'super_admin',
                'guard_name' => $guard,
            ]);

            DB::connection('tenant')->table('model_has_roles')->insertOrIgnore([
                'role_id' => $role->id,
                'model_type' => User::class,
                'model_id' => $user->id,
            ]);
        }
    }

    /**
     * Keep geography copies, reference seeders, and the completion marker in one transaction.
     */
    public function configureAccount(Tenant $tenant): void
    {
        $originalConnection = DB::getDefaultConnection();
        $originalReferenceConnection = config('multitenancy.geographic_reference_connection_name');
        try {
            $tenant->makeCurrent();
            $this->migrateSchema(['database/migrations/tenant'], function (int $completed, int $total) use ($tenant): void {
                $tenant->forceFill([
                    'master_setup_progress' => $total > 0 ? (int) floor(70 * $completed / $total) : 70,
                    'master_setup_step' => 'Creating workspace tables ('.$completed.' of '.$total.')',
                ])->saveQuietly();
            });
            $tenant->forceFill(['master_setup_step' => 'Configuring access permissions', 'master_setup_progress' => 72])->saveQuietly();
            $this->copyAccessControl();
            $configuration = config('database.connections.'.$this->landlordConnection());
            config([
                'database.connections.tenant_setup' => array_merge($configuration, ['database' => $tenant->database, 'url' => null]),
                'multitenancy.geographic_reference_connection_name' => 'tenant_setup',
            ]);
            DB::purge('tenant_setup');
            DB::setDefaultConnection('tenant_setup');
            DB::connection('tenant_setup')->transaction(function () use ($tenant): void {
                $organization = Organization::query()->orderBy('id')->lockForUpdate()->firstOrFail();
                if ($organization->metadata['master_data_ready'] ?? false) {
                    return;
                }
                $tenant->forceFill(['master_setup_step' => 'Preparing master data', 'master_setup_progress' => 75])->saveQuietly();
                $this->seedReferenceData();
                $organization->refresh();
                $metadata = $organization->metadata ?? [];
                $metadata['master_data_ready'] = true;
                $organization->forceFill(['metadata' => $metadata])->saveQuietly();
            });
            $this->deleteLandlordPermissions();
        } finally {
            if ($tenant->isCurrent()) {
                $tenant->forgetCurrent();
            }
            DB::setDefaultConnection($originalConnection);
            config(['multitenancy.geographic_reference_connection_name' => $originalReferenceConnection]);
            DB::purge('tenant_setup');
            config(['database.connections.tenant_setup' => null]);
        }
    }

    protected function migrateSchema(array $paths, callable $progress): void
    {
        app(TenantSchemaMigrator::class)->run($paths, $progress);
    }

    protected function seedReferenceData(): void
    {
        $organization = new OrganizationSeeder;
        $organization->setContainer(app());
        $organization->setCommand($this->silentSeederCommand());
        $organization->run();

        $seeder = new class(Tenant::current()) extends DatabaseSeeder
        {
            public function __construct(private Tenant $tenant) {}

            public function call($class, $silent = false, array $parameters = []): static
            {
                $classes = (array) $class;
                foreach ($classes as $index => $seeder) {
                    $this->tenant->forceFill(['master_setup_step' => 'Preparing master data ('.($index + 1).' of '.count($classes).')', 'master_setup_progress' => 75 + (int) floor(24 * $index / max(1, count($classes)))])->saveQuietly();
                    parent::call($seeder, $silent, $parameters);
                }

                return $this;
            }
        };
        $seeder->setContainer(app());
        $seeder->setCommand($this->silentSeederCommand());
        $seeder->run();
    }

    private function silentSeederCommand(): Command
    {
        return new class extends Command
        {
            protected $signature = 'tenant:seed';

            public function __construct()
            {
                parent::__construct();

                $this->output = new OutputStyle(
                    new ArrayInput([]),
                    new NullOutput,
                );
            }
        };
    }

    protected function copyAccessControl(): void
    {
        $landlord = $this->landlordConnection();

        $permissions = Permission::on($landlord)->get()
            ->reject(fn (Permission $permission) => $this->isLandlordOnlyPermission($permission->name))
            ->values();

        foreach (['web', 'tenant'] as $guard) {
            $rows = $permissions->map(fn (Permission $permission) => [
                'name' => $permission->name,
                'guard_name' => $guard,
                'created_at' => $permission->created_at ?: now(),
                'updated_at' => $permission->updated_at ?: now(),
            ])->all();

            if ($rows !== []) {
                DB::connection('tenant')->table('permissions')->insertOrIgnore($rows);
            }
        }

        $roles = Role::on($landlord)->withoutGlobalScopes()->get()
            ->reject(fn (Role $role) => $role->name === null)
            ->values();

        foreach (['web', 'tenant'] as $guard) {
            $rows = $roles->map(fn (Role $role) => [
                'name' => $role->name,
                'guard_name' => $guard,
                'created_at' => $role->created_at ?: now(),
                'updated_at' => $role->updated_at ?: now(),
            ])->all();

            if ($rows !== []) {
                DB::connection('tenant')->table('roles')->insertOrIgnore($rows);
            }
        }

        $landlordRolePermissions = DB::connection($landlord)->table('role_has_permissions')->get();
        $landlordPermissionNames = DB::connection($landlord)->table('permissions')->pluck('name', 'id');
        $landlordRoleNames = DB::connection($landlord)->table('roles')->pluck('name', 'id');

        foreach (['web', 'tenant'] as $guard) {
            $permissionIds = DB::connection('tenant')->table('permissions')
                ->where('guard_name', $guard)
                ->pluck('id', 'name');
            $roleIds = DB::connection('tenant')->table('roles')
                ->where('guard_name', $guard)
                ->pluck('id', 'name');

            $rows = [];
            foreach ($landlordRolePermissions as $link) {
                $permissionName = $landlordPermissionNames[$link->permission_id] ?? null;
                $roleName = $landlordRoleNames[$link->role_id] ?? null;

                if (! is_string($permissionName) || $this->isLandlordOnlyPermission($permissionName)) {
                    continue;
                }

                $permissionId = $permissionIds[$permissionName] ?? null;
                $roleId = $roleIds[$roleName] ?? null;

                if ($permissionId && $roleId) {
                    $rows[] = [
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ];
                }
            }

            if ($rows !== []) {
                DB::connection('tenant')->table('role_has_permissions')->insertOrIgnore($rows);
            }
        }
    }

    protected function deleteLandlordPermissions(): void
    {
        $ids = DB::connection('tenant')->table('permissions')
            ->pluck('name', 'id')
            ->filter(fn ($name) => $this->isLandlordOnlyPermission((string) $name))
            ->keys();

        if ($ids->isEmpty()) {
            return;
        }

        $schema = DB::connection('tenant')->getSchemaBuilder();
        DB::connection('tenant')->table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        if ($schema->hasTable('model_has_permissions')) {
            DB::connection('tenant')->table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        }
        DB::connection('tenant')->table('permissions')->whereIn('id', $ids)->delete();
    }

    private function isLandlordOnlyPermission(string $name): bool
    {
        $subject = str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : $name;

        return $subject === 'Tenant' || $subject === 'TenantUser';
    }

    private function cleanup(Tenant $tenant): void
    {
        if ($tenant->isCurrent()) {
            $tenant->forgetCurrent();
        }

        $this->databases->drop($tenant);

        $tenant->forceFill([
            'db_username' => null,
            'db_password' => null,
        ])->saveQuietly();
    }

    private function landlordConnection(): string
    {
        return (string) config('multitenancy.landlord_database_connection_name', 'mysql');
    }
}
