<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Spatie\Multitenancy\Contracts\IsTenant;

class MigrateTenantsCommand extends Command
{
    protected $signature = 'tenants:migrate {--fresh : Drop tenant tables before migrating}';

    protected $description = 'Migrate the landlord registry, then every tenant database.';

    public function handle(): int
    {
        $landlord = (string) config('multitenancy.landlord_database_connection_name', 'pgsql');

        $this->components->info('Migrating the landlord database.');
        $exit = Artisan::call('migrate', [
            '--database' => $landlord,
            '--force' => true,
        ]);
        $this->output->write(Artisan::output());

        if ($exit !== self::SUCCESS) {
            return self::FAILURE;
        }

        app(IsTenant::class)::query()->orderBy('id')->each(function ($tenant) use (&$exit): void {
            $this->components->info("Migrating {$tenant->domain}.");

            try {
                $tenant->execute(function () {
                    $arguments = [
                        '--database' => 'tenant',
                        '--path' => 'database/migrations/tenant',
                        '--force' => true,
                    ];

                    $result = Artisan::call($this->option('fresh') ? 'migrate:fresh' : 'migrate', $arguments);
                    $this->output->write(Artisan::output());
                    if ($result !== self::SUCCESS) {
                        throw new \RuntimeException('Tenant migrations failed.');
                    }
                });
            } catch (\Throwable $exception) {
                $exit = self::FAILURE;
                $this->components->error($tenant->domain.': '.$exception->getMessage());
            }
        });

        return $exit;
    }
}
