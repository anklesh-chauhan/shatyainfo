<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class TenantDatabaseProvisioner
{
    public function createDatabase(Tenant $tenant): void
    {
        $database = $this->quoteIdentifier($tenant->database);

        DB::connection($this->landlordConnection())->statement(
            "CREATE DATABASE {$database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    public function createDedicatedUser(Tenant $tenant): void
    {
        $username = 'tu_'.bin2hex(random_bytes(8));
        $password = Str::password(40, symbols: false);
        $account = $this->quoteAccount($username);
        $database = str_replace('_', '\\_', $this->quoteIdentifier($tenant->database));
        $quotedPassword = DB::connection($this->landlordConnection())->getPdo()->quote($password);

        $landlord = DB::connection($this->landlordConnection());
        $landlord->statement("CREATE USER {$account} IDENTIFIED BY {$quotedPassword}");
        $landlord->statement("GRANT ALL PRIVILEGES ON {$database}.* TO {$account}");

        $tenant->forceFill([
            'db_username' => $username,
            'db_password' => $password,
        ])->saveQuietly();
    }

    public function drop(Tenant $tenant): void
    {
        $databaseName = (string) $tenant->database;
        if ($databaseName === '') {
            return;
        }

        $landlord = DB::connection($this->landlordConnection());

        try {
            $landlord->statement('DROP DATABASE IF EXISTS '.$this->quoteIdentifier($databaseName));
        } catch (Throwable $exception) {
            Log::error('Failed to drop tenant database.', [
                'tenant_id' => $tenant->id,
                'message' => $exception->getMessage(),
            ]);
        }

        $username = (string) $tenant->db_username;
        if ($username === '') {
            return;
        }

        try {
            $landlord->statement('DROP USER IF EXISTS '.$this->quoteAccount($username));
        } catch (Throwable $exception) {
            Log::error('Failed to drop tenant database user.', [
                'tenant_id' => $tenant->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe database identifier.');
        }

        return '`'.$identifier.'`';
    }

    public function quoteAccount(string $username): string
    {
        $host = (string) config('multitenancy.tenant_database_user_host', '%');

        if (! preg_match('/^[%A-Za-z0-9.:_-]+$/', $host)) {
            throw new InvalidArgumentException('Unsafe database user host.');
        }

        return $this->quoteIdentifier($username)."@'".str_replace("'", '', $host)."'";
    }

    private function landlordConnection(): string
    {
        return (string) config('multitenancy.landlord_database_connection_name', 'mysql');
    }
}
