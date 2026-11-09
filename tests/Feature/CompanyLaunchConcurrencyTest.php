<?php

use App\Models\FieldPlanningActivation;
use App\Models\FieldUserHq;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\CompanyLaunchService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/field_concurrency_support.php';

uses(FieldConcurrencyDatabase::class);

it('serializes overlapping company launches without duplicate history or partial failed teams', function () {
    expect(User::query()->getConnection()->getDatabaseName())->toBe('it_erp_testing');
    config(['multitenancy.switch_tenant_tasks' => [], 'database.connections.tenant' => config('database.connections.mysql')]);
    $connection = app('db')->connection('mysql');
    app('db')->connection('tenant')->setPdo($connection->getPdo())->setReadPdo($connection->getPdo());
    app('db')->setDefaultConnection('tenant');
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99997, 'name' => 'Isolated launch acceptance', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    try {
        $admin = reportingUser('Concurrent Launch Admin');
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $organization = Organization::query()->create(['name' => 'Concurrent Pharma', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
        $hq = reportingLocation('Concurrent HQ');
        $reps = [];
        for ($index = 0; $index < 4; $index++) {
            $rep = reportingUser('Concurrent Launch MR '.$index);
            foreach (['Create:Visit', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster'] as $permission) {
                $rep->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
            FieldUserHq::query()->create(['user_id' => $rep->id, 'location_master_id' => $hq->id, 'updated_by' => $admin->id]);
            reportingDoctor($rep, 'Concurrent Launch Doctor '.$index);
            $reps[] = $rep;
        }
        $launch = app(CompanyLaunchService::class);
        $launch->saveWorkflow('direct_dcr', 0);
        app(OnboardingApprovalService::class)->saveDraft(['required' => 'no']);
        $revision = $launch->proposal($organization->fresh());
        $job = ['mode' => 'company_launch', 'actor_id' => $admin->id, 'revision_id' => $revision->id];
        $duplicate = [...$job, 'employee_ids' => [$reps[0]->employee->id]];
        $results = runFieldWorkers(array_fill(0, 4, $duplicate));
        expect(array_column($results, 'status'))->toBe([200, 200, 200, 200], json_encode($results))
            ->and(FieldPlanningActivation::query()->count())->toBe(1)
            ->and($launch->history())->toHaveCount(1)
            ->and($launch->history()[0]['members'])->toHaveCount(1);

        $firstHistory = $launch->history()[0];
        $results = [...$results, ...runFieldWorkers([
            [...$job, 'employee_ids' => [$reps[0]->employee->id, $reps[1]->employee->id]],
            [...$job, 'employee_ids' => [$reps[1]->employee->id, $reps[2]->employee->id]],
            [...$job, 'employee_ids' => [$reps[2]->employee->id]],
            [...$job, 'employee_ids' => [$reps[3]->employee->id, $admin->employee->id]],
        ])];
        expect(array_count_values(array_column($results, 'status')))->toBe([200 => 7, 422 => 1]);
        $launchedIds = collect($launch->history())->flatMap(fn (array $event): array => array_column($event['members'], 'user_id'))->all();
        sort($launchedIds);
        $expected = [$reps[0]->id, $reps[1]->id, $reps[2]->id];
        sort($expected);
        expect($launchedIds)->toBe($expected)
            ->and(FieldPlanningActivation::query()->count())->toBe(1)
            ->and($launch->forUser($reps[3])['launched'])->toBeFalse()
            ->and($launch->forUser($admin)['launched'])->toBeFalse()
            ->and(collect($launch->history())->last())->toBe($firstHistory);
        $timeline = [];
        foreach ($results as $result) {
            $timeline[] = [$result['start'], 1];
            $timeline[] = [$result['end'], -1];
        }
        sort($timeline);
        $active = $peak = 0;
        foreach ($timeline as [, $delta]) {
            $active += $delta;
            $peak = max($peak, $active);
        }
        expect($peak)->toBeGreaterThan(1);
        fwrite(STDOUT, "\nCOMPANY_LAUNCH_CONCURRENCY ".json_encode(['workers' => 4, 'requests' => 8,
            'peak_overlap' => $peak, 'successful' => 7, 'rejected_unready_team' => 1,
            'activated_policies' => 1, 'unique_launched_mrs' => count($launchedIds)])."\n");
    } finally {
        Tenant::forgetCurrent();
        app('db')->setDefaultConnection('mysql');
    }
})->group('performance')->skip(fn (): bool => getenv('FIELD_CONCURRENCY') !== '1', 'Run with -Concurrency against the isolated test database; do not run competing database suites.');
