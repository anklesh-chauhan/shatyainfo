<?php

namespace App\Tasks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Exceptions\InvalidConfiguration;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class CustomSwitchTenantDatabaseTask implements SwitchTenantTask
{
    use UsesMultitenancyConfig;

    public function __construct(
        protected ?string $originalDefaultConnection = null,
        protected ?string $landlordUsername = null,
        protected ?string $landlordPassword = null,
    ) {
        $landlord = $this->landlordDatabaseConnectionName();

        $this->originalDefaultConnection ??= (string) config('database.default');
        $this->landlordUsername ??= (string) config("database.connections.{$landlord}.username");
        $this->landlordPassword ??= (string) config("database.connections.{$landlord}.password");
    }

    public function makeCurrent(IsTenant $tenant): void
    {
        $username = $tenant->getAttribute('db_username');
        $password = $username
            ? (string) $tenant->getAttribute('db_password')
            : $this->landlordPassword;

        $this->setTenantConnection(
            databaseName: $tenant->getDatabaseName(),
            username: $username ? (string) $username : (string) $this->landlordUsername,
            password: $password,
            defaultConnection: $this->tenantDatabaseConnectionName(),
        );
    }

    public function forgetCurrent(): void
    {
        $this->setTenantConnection(
            databaseName: null,
            username: (string) $this->landlordUsername,
            password: (string) $this->landlordPassword,
            defaultConnection: (string) $this->originalDefaultConnection,
        );
    }

    protected function setTenantConnection(
        ?string $databaseName,
        string $username,
        string $password,
        string $defaultConnection,
    ): void {
        $tenantConnectionName = $this->tenantDatabaseConnectionName();

        if ($tenantConnectionName === $this->landlordDatabaseConnectionName()) {
            throw InvalidConfiguration::tenantConnectionIsEmptyOrEqualsToLandlordConnection();
        }

        if (is_null(config("database.connections.{$tenantConnectionName}"))) {
            throw InvalidConfiguration::tenantConnectionDoesNotExist($tenantConnectionName);
        }

        config([
            "database.connections.{$tenantConnectionName}.database" => $databaseName,
            "database.connections.{$tenantConnectionName}.username" => $username,
            "database.connections.{$tenantConnectionName}.password" => $password,
        ]);

        app('db')->extend($tenantConnectionName, function ($config, $name) use ($databaseName, $username, $password) {
            $config['database'] = $databaseName;
            $config['username'] = $username;
            $config['password'] = $password;

            return app('db.factory')->make($config, $name);
        });

        DB::purge($tenantConnectionName);
        DB::setDefaultConnection($defaultConnection);
        Model::setConnectionResolver(app('db'));
    }
}
