<?php

use App\Enums\FieldPlanningMode;
use App\Models\AccountAssignmentHistory;
use App\Models\AccountCallTarget;
use App\Models\AccountMaster;
use App\Models\ApprovalSetting;
use App\Models\FieldSyncReceipt;
use App\Models\FieldSyncRecovery;
use App\Models\FieldVisitEvent;
use App\Models\FlexiblePlanEntry;
use App\Models\SalesDcr;
use App\Models\TypeMaster;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitPreference;
use App\Services\Field\FieldCoverageService;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/field_concurrency_support.php';

uses(FieldConcurrencyDatabase::class);

it('preserves mixed visit chains and reporting totals through a bounded concurrent soak', function () {
    expect(User::query()->getConnection()->getDatabaseName())->toBe('it_erp_testing');
    $duration = getenv('FIELD_SOAK_SMOKE') === '1' ? 2 : 120;
    $actors = collect(range(1, 4))->map(fn ($index) => reportingUser('Mixed soak MR '.$index));
    $this->actingAs($actors->first());
    $actors->first()->givePermissionTo(Permission::findOrCreate('Manage:FieldPlanningPreference', 'web'));
    $settings = app(FieldPlanningSettingsService::class);
    $revision = $settings->saveProposal($actors->first(), ['mode' => 'flexible', 'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::Flexible), 'reason' => 'Isolated mixed-write soak'], 0);
    $settings->activate($actors->first(), $revision->id, 1);
    ApprovalSetting::instance()->update(['enabled_modules' => []]);
    VisitPreference::current()->update(['require_gps' => false, 'enforce_geo_fencing' => false, 'field_rules' => [],
        'enable_check_in' => true, 'enable_check_out' => true, 'require_check_in_image' => false,
        'require_check_out_image' => false, 'require_general_visit_image' => false, 'enforce_minimum_duration' => false]);
    $type = TypeMaster::query()->create(['name' => 'Mixed soak doctor', 'typeable_type' => AccountMaster::class]);
    $users = $actors->all();
    $password = User::factory()->make()->getAttributes()['password'];
    User::query()->getConnection()->transaction(function () use (&$users, $password, $type): void {
        for ($rep = 4; $rep < 100; $rep++) {
            $users[] = User::query()->create(['name' => 'Soak background MR '.$rep,
                'email' => 'soak-background-'.$rep.'@example.test', 'password' => $password]);
        }
        foreach ($users as $user) {
            $base = (int) AccountMaster::withTrashed()->max('id') + 1;
            $accounts = $assignments = $targets = [];
            for ($doctor = 0; $doctor < 250; $doctor++) {
                $id = $base + $doctor;
                $accounts[] = ['id' => $id, 'owner_id' => $user->id, 'type_master_id' => $type->id, 'name' => 'Soak doctor '.$id];
                $assignments[] = ['account_master_id' => $id, 'owner_id' => $user->id, 'patch_ids' => '[]', 'effective_from' => today()->startOfMonth()->toDateTimeString()];
                $targets[] = ['account_master_id' => $id, 'calls_per_month' => 2, 'effective_from' => today()->startOfMonth()->toDateString()];
            }
            AccountMaster::query()->insert($accounts);
            AccountAssignmentHistory::query()->insert($assignments);
            AccountCallTarget::query()->insert($targets);
        }
    });
    $jobs = [];
    foreach ($actors as $actor) {
        foreach (['Create:Visit', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $jobs[] = ['token' => $actor->createToken('isolated-mixed-soak')->plainTextToken, 'mode' => 'mixed',
            'duration_seconds' => $duration, 'user_id' => $actor->id, 'date' => today()->toDateString(),
            'month' => today()->format('Y-m'), 'week' => today()->startOfWeek()->toDateString(),
            'account_ids' => AccountMaster::query()->where('owner_id', $actor->id)->orderBy('id')->pluck('id')->all()];
    }
    fwrite(STDOUT, "\nFIELD_MIXED_SOAK_START ".json_encode(['mrs' => 100, 'customers_per_mr' => 250,
        'workers' => 4, 'minimum_seconds_per_worker' => $duration, 'transport' => 'independent PHP HTTP kernels; no network'])."\n");
    $rows = runFieldWorkers($jobs);
    $summaries = array_values(array_filter($rows, fn ($row) => $row['kind'] === 'mixed_summary'));
    $requests = array_values(array_filter($rows, fn ($row) => $row['kind'] !== 'mixed_summary'));
    expect($summaries)->toHaveCount(4);
    $total = 0;
    foreach ($summaries as $summary) {
        $cycles = $summary['cycles'];
        $total += $cycles;
        expect($cycles)->toBeGreaterThan(0)->and($summary['seconds'])->toBeGreaterThanOrEqual($duration)
            ->and($summary['peak_memory_bytes'])->toBeLessThan(512 * 1024 * 1024);
        $visits = Visit::query()->where('employee_id', $summary['user_id'])->get();
        expect($visits)->toHaveCount($cycles)->and($visits->pluck('client_call_id')->unique())->toHaveCount($cycles)
            ->and($visits->where('visit_status', 'completed'))->toHaveCount($cycles)
            ->and($visits->filter(fn ($visit) => $visit->remarks && str_contains($visit->remarks, 'Soak original note')))->toHaveCount($cycles)
            ->and($visits->filter(fn ($visit) => $visit->start_time && $visit->end_time && $visit->start_time->lte($visit->end_time)))->toHaveCount($cycles);
        expect(SalesDcr::query()->where('user_id', $summary['user_id'])->sole()->approval_status)->toBe('approved');
        $actor = User::query()->findOrFail($summary['user_id']);
        $this->actingAs($actor);
        $report = app(FieldCoverageService::class)->forMonth($actor, $actor->id, today()->format('Y-m'));
        expect($report['customers'])->toHaveCount(250)
            ->and(array_sum(array_column($report['customers'], 'completed_calls')))->toBe($cycles)
            ->and(array_sum(array_column($report['customers'], 'pending_calls')))->toBe(0);
        $this->postJson('/api/v1/visits/direct', ['client_call_id' => (string) Str::uuid(),
            'company_id' => AccountMaster::query()->where('owner_id', $actor->id)->value('id'),
            'date' => today()->toDateString(), 'unplanned_reason' => 'Locked DCR edit attempt'])->assertUnprocessable();
    }
    expect(Visit::query()->count())->toBe($total)
        ->and(Visit::query()->distinct()->count('document_number'))->toBe($total)
        ->and(FieldVisitEvent::query()->count())->toBe($total * 3)
        ->and(FieldSyncReceipt::query()->count())->toBe($total * 4 + 4)
        ->and(FieldSyncRecovery::query()->count())->toBe(0);
    $timeline = [];
    foreach ($requests as $row) {
        expect($row['status'])->toBeGreaterThanOrEqual(200)->toBeLessThan(300);
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
    foreach (array_unique(array_column($requests, 'kind')) as $kind) {
        $samples = array_column(array_values(array_filter($requests, fn ($row) => $row['kind'] === $kind)), 'ms');
        sort($samples);
        $p95 = $samples[(int) ceil(count($samples) * .95) - 1];
        fwrite(STDOUT, "\nFIELD_MIXED_SOAK_ENDPOINT ".json_encode(['kind' => $kind, 'samples' => count($samples),
            'p95_ms' => round($p95, 1), 'max_ms' => round(max($samples), 1)])."\n");
        expect($p95)->toBeLessThan(10000);
    }
    fwrite(STDOUT, "\nFIELD_MIXED_SOAK_RESULT ".json_encode(['workers' => $summaries, 'peak_overlapping_requests' => $peak,
        'successful_requests' => count($requests), 'completed_visits' => $total,
        'plans' => FlexiblePlanEntry::query()->count(), 'events' => FieldVisitEvent::query()->count(),
        'receipts' => FieldSyncReceipt::query()->count(), 'recoveries' => 0, 'approved_dcrs' => 4,
        'official_coverage_reconciled' => true, 'locked_dcr_edits_rejected' => true])."\n");
})->group('performance')->skip(fn (): bool => getenv('FIELD_SOAK') !== '1', 'Run the isolated backend runner with -Soak; do not run competing database suites.');

it('retries a direct opening deadlock after Laravel renders it and records only one receipt', function () {
    $this->rep = reportingUser('Deadlock retry MR');
    $this->actingAs($this->rep);
    foreach (['Create:Visit', 'Manage:FieldPlanningPreference', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster'] as $name) {
        $this->rep->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $doctor = reportingDoctor($this->rep, 'Deadlock retry doctor');
    $settings = app(FieldPlanningSettingsService::class);
    $revision = $settings->saveProposal($this->rep, [...$settings->initialProposal(), 'reason' => 'Deadlock retry regression'], 0);
    $settings->activate($this->rep, $revision->id, 1);
    $this->call = ['client_call_id' => (string) Str::uuid(), 'company_id' => $doctor->id, 'date' => today()->toDateString()];
    $attempts = 0;
    Visit::creating(function () use (&$attempts): void {
        if (++$attempts === 1) {
            throw new DeadlockException('Deadlock found when trying to get lock; try restarting transaction');
        }
    });
    $operation = (string) Str::uuid();
    $id = $this->postJson('/api/v1/visits/direct', $this->call, ['Idempotency-Key' => $operation])
        ->assertSuccessful()->json('data.id');
    $this->postJson('/api/v1/visits/direct', $this->call, ['Idempotency-Key' => $operation])
        ->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
    expect($attempts)->toBe(2)->and(Visit::query()->count())->toBe(1)
        ->and(FieldSyncReceipt::query()->where('operation_id', $operation)->count())->toBe(1);
})->group('performance')->skip(fn (): bool => getenv('FIELD_SOAK') !== '1', 'Run with -Soak against the committed isolated database.');
