<?php

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyOnboardingService;
use App\Services\DateFormatService;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/field_concurrency_support.php';
require_once __DIR__.'/company_isolation_support.php';

uses(FieldConcurrencyDatabase::class);

it('isolates real company databases during overlapping worker writes and repeated switching', function () {
    $main = app('db')->connection('mysql');
    expect($main->getDatabaseName())->toBe('it_erp_testing');
    $suffix = bin2hex(random_bytes(6));
    $companies = [
        ['id' => 99981, 'name' => 'Isolation Alpha', 'database' => 'it_erp_testing_iso_'.$suffix.'_a', 'format' => 'd/m/Y', 'expenses' => true],
        ['id' => 99982, 'name' => 'Isolation Beta', 'database' => 'it_erp_testing_iso_'.$suffix.'_b', 'format' => 'Y-m-d', 'expenses' => false],
    ];
    $created = [];
    try {
        $tables = $main->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        foreach ($companies as $company) {
            $database = $company['database'];
            if (! preg_match('/^it_erp_testing_iso_[a-f0-9]{12}_[ab]$/', $database)) {
                throw new RuntimeException('Invalid temporary schema name.');
            }
            $main->statement('CREATE DATABASE `'.$database.'`');
            $created[] = $database;
            config(['database.connections.isolation_clone' => [
                ...config('database.connections.mysql'), 'database' => $database,
            ]]);
            app('db')->purge('isolation_clone');
            $clone = app('db')->connection('isolation_clone');
            $clone->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($tables as $table) {
                $name = array_values((array) $table)[0];
                if (! preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                    throw new RuntimeException('Invalid table name.');
                }
                $definition = (array) $main->selectOne('SHOW CREATE TABLE `'.$name.'`');
                $clone->statement($definition['Create Table']);
            }
            $clone->statement('SET FOREIGN_KEY_CHECKS=1');
            app('db')->purge('isolation_clone');
            selectIsolationCompany($company);
            Organization::query()->create(['id' => 1, 'name' => $company['name'], 'metadata' => [
                'company_setup' => ['organization_saved' => true],
                'optional_modules' => ['expenses' => ['enabled' => $company['expenses']]],
            ]]);
            foreach (range(1, 4) as $id) {
                $user = User::factory()->create(['id' => $id]);
                auth()->setUser($user);
                $user->assignRole(Role::findOrCreate('super_admin', 'web'));
                app(CompanyOnboardingService::class)->saveDraft(['step' => 1,
                    'organization' => ['name' => $company['name'].'-'.$id],
                    'workflow' => '', 'workflow_version' => 0, 'approval' => []]);
            }
            app(DateFormatService::class)->save($company['format']);
            auth()->forgetGuards();
            Tenant::forgetCurrent();
        }
        $results = runFieldWorkers(array_map(fn (int $id): array => [
            'mode' => 'company_isolation', 'actor_id' => $id,
            'companies' => $id % 2 ? $companies : array_reverse($companies),
        ], range(1, 4)));
        expect(array_column($results, 'status'))->toBe([200, 200, 200, 200]);
        $overlapStart = max(array_column($results, 'start'));
        $overlapEnd = min(array_column($results, 'end'));
        expect($overlapStart)->toBeLessThan($overlapEnd);
        foreach ($companies as $company) {
            selectIsolationCompany($company);
            $organization = Organization::query()->sole();
            expect($organization->name)->toBe($company['name'])
                ->and($organization->metadata['isolation_writes'])->toBe(40)
                ->and(User::query()->count())->toBe(4);
            Tenant::forgetCurrent();
        }
        expect(Organization::query()->count())->toBe(0)
            ->and(User::query()->count())->toBe(0);
        fwrite(STDOUT, "\nSEPARATE_COMPANY_CONCURRENCY ".json_encode([
            'databases' => 2, 'workers' => 4, 'writes_per_company' => 40,
            'total_writes' => 80, 'all_workers_overlapped' => true,
        ])."\n");
    } finally {
        Tenant::forgetCurrent();
        app('db')->purge('isolation_clone');
        foreach ($created as $database) {
            if (! preg_match('/^it_erp_testing_iso_[a-f0-9]{12}_[ab]$/', $database)) {
                throw new RuntimeException('Refuse unexpected schema cleanup.');
            }
            $main->statement('DROP DATABASE `'.$database.'`');
        }
    }
})->group('performance')->skip(fn (): bool => getenv('FIELD_CONCURRENCY') !== '1', 'Run with -Concurrency against isolated test schemas.');
