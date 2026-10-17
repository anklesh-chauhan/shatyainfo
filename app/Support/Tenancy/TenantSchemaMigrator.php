<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationSkipped;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class TenantSchemaMigrator
{
    public const LOGIN_MIGRATIONS = [
        'database/migrations/tenant/0001_01_01_000000_create_users_table.php',
        'database/migrations/tenant/2025_03_10_160426_create_permission_tables.php',
        'database/migrations/tenant/2025_03_12_150626_create_industry_types_table.php',
        'database/migrations/tenant/2025_06_18_172037_create_organizations_table.php',
        'database/migrations/tenant/2025_12_18_110804_add_deleted_at_to_roles_table.php',
    ];

    /**
     * Use a private dispatcher so progress listeners do not accumulate in queue workers.
     *
     * @param  array<string>  $paths
     * @param  callable(int, int): void  $progress
     */
    public function run(array $paths, callable $progress): void
    {
        $repository = new DatabaseMigrationRepository(app('db'), config('database.migrations.table', 'migrations'));
        $events = new Dispatcher(app());
        $migrator = new Migrator($repository, app('db'), app('files'), $events);
        $migrator->usingConnection('tenant', function () use ($repository, $migrator, $events, $paths, $progress): void {
            if (! $repository->repositoryExists()) {
                $repository->createRepository();
            }
            $paths = array_map(base_path(...), $paths);
            $files = $migrator->getMigrationFiles($paths);
            if ($files === []) {
                throw new RuntimeException('No tenant migration files were found. Deploy the complete database/migrations/tenant directory.');
            }
            $pending = array_diff(array_keys($files), $repository->getRan());
            $total = count($pending);
            $completed = 0;
            $skipped = 0;
            $progress($completed, $total);
            $events->listen(MigrationEnded::class, function (MigrationEnded $event) use (&$completed, $total, $progress): void {
                if ($event->method === 'up') {
                    $progress(++$completed, $total);
                }
            });
            $events->listen(MigrationSkipped::class, function () use (&$skipped): void {
                $skipped++;
            });
            Log::info('Tenant schema migrations started.', ['database' => app('db')->connection('tenant')->getDatabaseName(), 'pending' => $total]);
            $command = new MigrateCommand($migrator, $events);
            $command->setLaravel(app());
            $exitCode = $command->run(new ArrayInput([
                '--database' => 'tenant',
                '--path' => $paths,
                '--realpath' => true,
                '--force' => true,
            ]), new NullOutput);
            if ($exitCode !== 0) {
                throw new RuntimeException('The tenant migration command failed. Master seeding has been stopped.');
            }
            if ($completed + $skipped !== $total) {
                throw new RuntimeException('Tenant migrations did not complete. Master seeding has been stopped.');
            }
            Log::info('Tenant schema migrations completed.', ['database' => app('db')->connection('tenant')->getDatabaseName(), 'completed' => $completed, 'skipped' => $skipped]);
        });
    }
}
