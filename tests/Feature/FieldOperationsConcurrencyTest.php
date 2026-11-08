<?php

use App\Enums\FieldPlanningMode;
use App\Models\AccountAssignmentHistory;
use App\Models\AccountCallTarget;
use App\Models\AccountMaster;
use App\Models\FieldSyncReceipt;
use App\Models\FlexiblePlanEntry;
use App\Models\SalesDcr;
use App\Models\TypeMaster;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitableVisit;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/field_concurrency_support.php';

uses(FieldConcurrencyDatabase::class);

it('measures concurrent enterprise report requests and serializes duplicate call openings', function () {
    expect(User::query()->getConnection()->getDatabaseName())->toBe('it_erp_testing');
    expect(runFieldWorkers([['token' => '', 'requests' => []]]))->toBe([]);
    $type = TypeMaster::query()->create(['name' => 'Concurrency Doctor', 'typeable_type' => AccountMaster::class]);
    $date = today()->toDateString();
    $month = today()->format('Y-m');
    $start = today()->startOfWeek()->toDateString();
    $password = User::factory()->make()->getAttributes()['password'];
    $userBase = (int) User::query()->max('id') + 1;
    $accountBase = (int) AccountMaster::withTrashed()->max('id') + 1;
    $visitBase = (int) Visit::withTrashed()->max('id') + 1;
    $dcrBase = (int) SalesDcr::withTrashed()->max('id') + 1;
    User::query()->getConnection()->transaction(function () use ($type, $date, $password, $userBase, $accountBase, $visitBase, $dcrBase): void {
        for ($rep = 0; $rep < 1000; $rep++) {
            $userId = $userBase + $rep;
            User::query()->insert(['id' => $userId, 'name' => 'Concurrent MR '.$rep, 'email' => 'concurrent-'.$rep.'@example.test', 'password' => $password]);
            SalesDcr::query()->insert(['id' => $dcrBase + $rep, 'user_id' => $userId, 'dcr_date' => $date, 'approval_status' => 'approved']);
            $accounts = $assignments = $targets = $visits = $links = $plans = [];
            for ($doctor = 0; $doctor < 100; $doctor++) {
                $accountId = $accountBase + $rep * 100 + $doctor;
                $accounts[] = ['id' => $accountId, 'owner_id' => $userId, 'type_master_id' => $type->id, 'name' => 'Concurrent doctor '.$accountId];
                $assignments[] = ['account_master_id' => $accountId, 'owner_id' => $userId, 'patch_ids' => '[]', 'effective_from' => today()->startOfMonth()->toDateTimeString()];
                $targets[] = ['account_master_id' => $accountId, 'calls_per_month' => 2, 'effective_from' => today()->startOfMonth()->toDateString()];
                $visitId = $visitBase + $rep * 100 + $doctor;
                $visits[] = ['id' => $visitId, 'document_number' => 'CONC-'.$visitId, 'employee_id' => $userId,
                    'sales_dcr_id' => $dcrBase + $rep, 'visit_date' => $date, 'start_time' => $date.' 10:00:00', 'visit_status' => 'completed', 'visit_type' => 'unplanned'];
                $links[] = ['visit_id' => $visitId, 'visitable_type' => AccountMaster::class, 'visitable_id' => $accountId];
                if ($doctor < 50) {
                    $plans[] = ['client_plan_id' => (string) Str::uuid(), 'user_id' => $userId, 'account_master_id' => $accountId, 'date' => $date, 'history' => '[]'];
                }
            }
            AccountMaster::query()->insert($accounts);
            AccountAssignmentHistory::query()->insert($assignments);
            AccountCallTarget::query()->insert($targets);
            Visit::query()->insert($visits);
            VisitableVisit::query()->insert($links);
            FlexiblePlanEntry::query()->insert($plans);
        }
    });
    fwrite(STDOUT, "\nFIELD_CONCURRENCY_ENV ".json_encode(['php' => PHP_VERSION,
        'mysql' => User::query()->getConnection()->selectOne('select version() as version')->version,
        'mrs' => 1000, 'customers' => 100000, 'visits' => 100000, 'intentions' => 50000,
        'mode' => 'independent PHP HTTP-kernel workers; no web-server/network transport'])."\n");
    $tokens = [];
    for ($index = 0; $index < 8; $index++) {
        $user = User::query()->findOrFail($userBase + $index);
        foreach (['ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster', 'Create:Visit'] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $tokens[] = $user->createToken('isolated-concurrency')->plainTextToken;
    }
    foreach ([4, 8] as $workers) {
        $jobs = [];
        for ($index = 0; $index < $workers; $index++) {
            $requests = [];
            for ($sample = 0; $sample < 5; $sample++) {
                $requests[] = ['kind' => 'coverage', 'url' => '/api/v1/field-coverage?month='.$month];
                $requests[] = ['kind' => 'weekly', 'url' => '/api/v1/flexible-plans?start='.$start];
            }
            $jobs[] = ['token' => $tokens[$index], 'requests' => $requests];
        }
        $results = runFieldWorkers($jobs);
        foreach ($results as $row) {
            expect($row['status'])->toBe(200)->and($row['count'])->toBe($row['kind'] === 'coverage' ? 100 : 50);
        }
        $timeline = [];
        foreach ($results as $row) {
            $timeline[] = [$row['start'], 1];
            $timeline[] = [$row['end'], -1];
        }
        sort($timeline);
        $active = $peak = 0;
        foreach ($timeline as [, $delta]) {
            $active += $delta;
            $peak = max($peak, $active);
        }
        expect($peak)->toBeGreaterThan(1);
        foreach (['coverage', 'weekly'] as $kind) {
            $samples = array_column(array_values(array_filter($results, fn ($row) => $row['kind'] === $kind)), 'ms');
            sort($samples);
            $p95 = $samples[(int) ceil(count($samples) * .95) - 1];
            fwrite(STDOUT, "\nFIELD_CONCURRENCY ".json_encode(['mrs' => 1000, 'customers' => 100000, 'visits' => 100000, 'intentions' => 50000,
                'workers' => $workers, 'peak_overlapping_requests' => $peak, 'endpoint' => $kind, 'samples' => count($samples), 'p95_ms' => round($p95, 1), 'max_ms' => round(max($samples), 1)])."\n");
            expect($p95)->toBeLessThan(5000);
        }
    }
    $actor = User::query()->findOrFail($userBase);
    $this->actingAs($actor);
    $actor->givePermissionTo(Permission::findOrCreate('Manage:FieldPlanningPreference', 'web'));
    $settings = app(FieldPlanningSettingsService::class);
    $revision = $settings->saveProposal($actor, ['mode' => 'direct_dcr', 'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::DirectDcr), 'reason' => 'Isolated contention test'], 0);
    $settings->activate($actor, $revision->id, 1);
    SalesDcr::query()->whereKey($dcrBase)->update(['approval_status' => 'draft']);
    $operation = (string) Str::uuid();
    $call = (string) Str::uuid();
    $request = ['kind' => 'direct', 'method' => 'POST', 'url' => '/api/v1/visits/direct', 'operation' => $operation,
        'body' => ['client_call_id' => $call, 'company_id' => $accountBase, 'date' => $date]];
    $results = runFieldWorkers(array_fill(0, 8, ['token' => $tokens[0], 'requests' => [$request]]));
    foreach ($results as $row) {
        expect($row['status'])->toBeGreaterThanOrEqual(200)->toBeLessThan(300);
    }
    expect(array_unique(array_column($results, 'visit_id')))->toHaveCount(1)
        ->and(count(array_filter($results, fn ($row) => $row['replayed'])))->toBe(7)
        ->and(Visit::query()->where('client_call_id', $call)->count())->toBe(1)
        ->and(FieldSyncReceipt::query()->where('operation_id', $operation)->count())->toBe(1);
    fwrite(STDOUT, "\nFIELD_CONCURRENCY_DUPLICATE ".json_encode(['workers' => 8, 'created_visits' => 1, 'receipts' => 1, 'replayed_responses' => 7])."\n");
})->group('performance')->skip(fn (): bool => getenv('FIELD_CONCURRENCY') !== '1', 'Run the isolated backend runner with -Concurrency; do not run competing database suites.');
