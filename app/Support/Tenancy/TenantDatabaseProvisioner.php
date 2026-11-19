<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class TenantDatabaseProvisioner
{
    public function createDatabase(Tenant $tenant): void
    {
        $database = $this->tenantDatabase((string) $tenant->database);
        $this->connection()->statement("CREATE DATABASE {$database} ENCODING 'UTF8'");
    }

    public function createDedicatedUser(Tenant $tenant): void
    {
        $database = $this->tenantDatabase((string) $tenant->database);
        $username = 'tu_'.bin2hex(random_bytes(8));
        $password = Str::password(40, symbols: false);
        $role = $this->quoteIdentifier($username);
        $connection = $this->connection();
        $quotedPassword = $connection->getPdo()->quote($password);

        $connection->transaction(function () use ($connection, $database, $role, $quotedPassword, $tenant, $username, $password): void {
            $connection->getPdo()->exec("CREATE ROLE {$role} LOGIN PASSWORD {$quotedPassword}");
            $connection->statement("GRANT {$role} TO CURRENT_USER WITH INHERIT TRUE, SET TRUE");
            $connection->statement("ALTER DATABASE {$database} OWNER TO {$role}");
            $connection->statement("REVOKE CONNECT, TEMPORARY ON DATABASE {$database} FROM PUBLIC");

            $tenant->forceFill([
                'db_username' => $username,
                'db_password' => $password,
            ])->saveQuietly();
        });
    }

    public function drop(Tenant $tenant): void
    {
        $databaseName = (string) $tenant->database;
        if ($databaseName === '') {
            return;
        }

        $database = $this->tenantDatabase($databaseName);
        $connection = $this->connection();

        try {
            $connection->statement("DROP DATABASE IF EXISTS {$database} WITH (FORCE)");
        } catch (Throwable $exception) {
            Log::error('Failed to drop tenant database.', [
                'tenant_id' => $tenant->id,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $username = (string) $tenant->db_username;
        if ($username === '') {
            return;
        }

        try {
            if (! preg_match('/^tu_[a-f0-9]{16}$/D', $username)) {
                throw new InvalidArgumentException('Invalid tenant database role.');
            }

            $connection->statement('DROP ROLE IF EXISTS '.$this->quoteIdentifier($username));
        } catch (Throwable $exception) {
            Log::error('Failed to drop tenant database role.', [
                'tenant_id' => $tenant->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,63}$/D', $identifier)) {
            throw new InvalidArgumentException('Unsafe database identifier.');
        }

        return '"'.$identifier.'"';
    }

    private function tenantDatabase(string $name): string
    {
        $reserved = [
            'postgres', 'template0', 'template1',
            config('database.connections.pgsql.database'),
            config('database.connections.pgsql_provisioning.database'),
            config('database.connections.geographic_reference.database'),
        ];
        if (in_array($name, $reserved, true)) {
            throw new InvalidArgumentException('A shared database cannot be used as a tenant database.');
        }

        return $this->quoteIdentifier($name);
    }

    private function connection(): Connection
    {
        return DB::connection('pgsql_provisioning');
    }
}
