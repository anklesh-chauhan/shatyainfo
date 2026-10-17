<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;

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
            $pending = array_diff(array_keys($migrator->getMigrationFiles($paths)), $repository->getRan());
            $total = count($pending);
            $completed = 0;
            $progress($completed, $total);
            $events->listen(MigrationEnded::class, function (MigrationEnded $event) use (&$completed, $total, $progress): void {
                if ($event->method === 'up') {
                    $progress(++$completed, $total);
                }
            });
            $migrator->run($paths);
        });
    }
}
