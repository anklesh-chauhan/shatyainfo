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
        $landlord = (string) config('multitenancy.landlord_database_connection_name', 'mysql');

        $this->components->info('Migrating the landlord database.');
        Artisan::call('migrate', [
            '--database' => $landlord,
            '--path' => 'database/migrations',
            '--force' => true,
        ]);
        $this->output->write(Artisan::output());

        $exit = self::SUCCESS;

        app(IsTenant::class)::query()->orderBy('id')->each(function ($tenant) use (&$exit): void {
            $this->components->info("Migrating {$tenant->domain}.");

            try {
                $tenant->execute(function () {
                    $arguments = [
                        '--database' => 'tenant',
                        '--path' => 'database/migrations/tenant',
                        '--force' => true,
                    ];

                    if ($this->option('fresh')) {
                        Artisan::call('migrate:fresh', $arguments);
                    } else {
                        Artisan::call('migrate', $arguments);
                    }

                    $this->output->write(Artisan::output());
                });
            } catch (\Throwable $exception) {
                $exit = self::FAILURE;
                $this->components->error($tenant->domain.': '.$exception->getMessage());
            }
        });

        return $exit;
    }
}
