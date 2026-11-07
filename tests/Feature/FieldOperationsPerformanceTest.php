<?php

use App\Models\AccountAssignmentHistory;
use App\Models\AccountCallTarget;
use App\Models\AccountMaster;
use App\Models\FlexiblePlanEntry;
use App\Models\SalesDcr;
use App\Models\TypeMaster;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitableVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('keeps coverage and weekly review bounded as enterprise tenant data grows', function () {
    expect(User::query()->getConnection()->getDatabaseName())->toBe('it_erp_testing');
    fwrite(STDOUT, "\nFIELD_PERFORMANCE_ENV ".json_encode([
        'php' => PHP_VERSION, 'database' => User::query()->getConnection()->selectOne('select version() as version')->version,
        'mode' => 'sequential in-process Laravel API requests; no network or concurrent clients',
    ])."\n");
    $this->travelTo(Carbon::parse('2026-06-30 18:00:00'));
    $type = TypeMaster::query()->create(['name' => 'Performance Doctor', 'typeable_type' => AccountMaster::class]);
    $userBase = (int) User::query()->max('id') + 1;
    $accountBase = (int) AccountMaster::withTrashed()->max('id') + 1;
    $visitBase = (int) Visit::withTrashed()->max('id') + 1;
    $dcrBase = (int) SalesDcr::withTrashed()->max('id') + 1;
    $password = User::factory()->make()->getAttributes()['password'];
    $seeded = 0;
    foreach ([100, 500, 1000] as $representatives) {
        // Bulk fixtures deliberately exclude observers/workflow writes from read-path timing.
        for ($rep = $seeded; $rep < $representatives; $rep++) {
            $userId = $userBase + $rep;
            User::query()->insert(['id' => $userId, 'name' => 'Performance MR '.$rep,
                'email' => 'performance-'.$rep.'@example.test', 'password' => $password]);
            $dcrs = [];
            foreach (['2026-06-06', '2026-06-13'] as $round => $date) {
                $dcrs[] = ['id' => $dcrBase + $rep * 2 + $round, 'user_id' => $userId,
                    'dcr_date' => $date, 'approval_status' => 'approved'];
            }
            SalesDcr::query()->insert($dcrs);
            $accounts = $assignments = $targets = $visits = $links = $plans = [];
            for ($doctor = 0; $doctor < 100; $doctor++) {
                $accountId = $accountBase + $rep * 100 + $doctor;
                $accounts[] = ['id' => $accountId, 'owner_id' => $userId, 'type_master_id' => $type->id,
                    'name' => 'Doctor '.$rep.' '.$doctor];
                $assignments[] = ['account_master_id' => $accountId, 'owner_id' => $userId,
                    'patch_ids' => '[]', 'effective_from' => '2026-06-01 00:00:00'];
                $targets[] = ['account_master_id' => $accountId, 'calls_per_month' => 2, 'effective_from' => '2026-06-01'];
                foreach (['2026-06-06', '2026-06-13'] as $round => $date) {
                    $visitId = $visitBase + ($rep * 100 + $doctor) * 2 + $round;
                    $visits[] = ['id' => $visitId, 'document_number' => 'PERF-'.$visitId,
                        'employee_id' => $userId, 'sales_dcr_id' => $dcrBase + $rep * 2 + $round,
                        'visit_date' => $date, 'start_time' => $date.' 10:00:00',
                        'visit_status' => 'completed', 'visit_type' => 'unplanned'];
                    $links[] = ['visit_id' => $visitId, 'visitable_type' => AccountMaster::class, 'visitable_id' => $accountId];
                }
                if ($doctor < 50) {
                    $plans[] = ['client_plan_id' => sprintf('00000000-0000-4000-8000-%012d', $accountId),
                        'user_id' => $userId, 'account_master_id' => $accountId,
                        'date' => '2026-06-'.(22 + intdiv($doctor, 10)), 'history' => '[]'];
                }
            }
            AccountMaster::query()->insert($accounts);
            AccountAssignmentHistory::query()->insert($assignments);
            AccountCallTarget::query()->insert($targets);
            Visit::query()->insert($visits);
            VisitableVisit::query()->insert($links);
            FlexiblePlanEntry::query()->insert($plans);
        }
        $seeded = $representatives;
        fwrite(STDOUT, "\nFIELD_PERFORMANCE_SEEDED ".$representatives." MRs\n");
        $actor = User::query()->findOrFail($userBase);
        foreach (['ViewAny:AccountMaster', 'Manage:FieldTeam'] as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        Sanctum::actingAs($actor);
        $connection = $actor->getConnection();
        foreach (['coverage' => '/api/v1/field-coverage?month=2026-06',
            'weekly_team_review' => '/api/v1/flexible-plans?start=2026-06-22&user_id='.($userBase + $representatives - 1)] as $name => $url) {
            $samples = [];
            $maxQueries = 0;
            $bytes = 0;
            $coldMs = null;
            for ($sample = 0; $sample <= 20; $sample++) {
                $connection->flushQueryLog();
                $connection->enableQueryLog();
                $started = hrtime(true);
                try {
                    $response = $this->getJson($url);
                    $elapsed = (hrtime(true) - $started) / 1_000_000;
                    $queries = count($connection->getQueryLog());
                } finally {
                    $connection->disableQueryLog();
                }
                $response->assertSuccessful();
                if ($name === 'coverage') {
                    $response->assertJsonCount(100, 'data.customers')->assertJsonPath('data.target_calls', 200)
                        ->assertJsonPath('data.credited_frequency_calls', 200)->assertJsonPath('data.covered_customers', 100);
                } else {
                    $response->assertJsonCount(50, 'data.entries')->assertJsonPath('data.intended', 50)
                        ->assertJsonPath('data.missed', 50)->assertJsonPath('data.user_id', $userBase + $representatives - 1);
                }
                $bytes = max($bytes, strlen($response->getContent()));
                $maxQueries = max($maxQueries, $queries);
                if ($sample === 0) {
                    $coldMs = $elapsed;
                } else {
                    $samples[] = $elapsed;
                }
            }
            sort($samples);
            $p95 = $samples[18];
            fwrite(STDOUT, "\nFIELD_PERFORMANCE ".json_encode([
                'mrs' => $representatives, 'customers' => $representatives * 100,
                'visits' => $representatives * 200, 'intentions' => $representatives * 50,
                'endpoint' => $name, 'samples' => 20, 'first_request_ms' => round($coldMs, 1),
                'median_ms' => round(($samples[9] + $samples[10]) / 2, 1), 'p95_ms' => round($p95, 1),
                'max_queries' => $maxQueries, 'response_bytes' => $bytes,
            ])."\n");
            expect($maxQueries)->toBeLessThanOrEqual(25)
                ->and($p95)->toBeLessThan($name === 'coverage' ? 2000 : 1000);
        }
    }
})->group('performance')->skip(fn (): bool => getenv('FIELD_PERFORMANCE') !== '1', 'Run the isolated backend runner with -Performance.');
