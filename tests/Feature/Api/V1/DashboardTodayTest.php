<?php

use App\Models\SalesDcr;
use App\Models\Territory;
use App\Services\Reports\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('requires authentication for the today counts', function () {
    $this->getJson('/api/v1/dashboard/today')->assertUnauthorized();
});

it('returns the signed-in user dashboard counts and hides another user dcr', function () {
    $north = Territory::query()->create([
        'name' => 'North',
        'code' => fake()->unique()->bothify('T###'),
    ]);
    $south = Territory::query()->create([
        'name' => 'South',
        'code' => fake()->unique()->bothify('T###'),
    ]);

    $rep = reportingUser('TodayCounts');
    $other = reportingUser('TodayCountsOther');

    $this->actingAs($rep);

    SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $rep->id,
        'approval_status' => 'draft',
        'territory_id' => $north->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $other->id,
        'approval_status' => 'pending',
        'territory_id' => $south->id,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $rep->id,
        'approval_status' => 'approved',
        'territory_id' => $north->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    grantPagePermissions($rep, 'View:Dashboard');
    Sanctum::actingAs($rep);

    $counts = app(DashboardMetrics::class)->forUser($rep);

    $this->getJson('/api/v1/dashboard/today')
        ->assertSuccessful()
        ->assertJsonPath('data.visits_today', $counts->visitsToday)
        ->assertJsonPath('data.pending_dcrs', 1)
        ->assertJsonPath('data.pending_approvals', $counts->pendingApprovals)
        ->assertJsonPath('data.punched_in_today', $counts->punchedInToday);

    $this->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonMissingPath('data.pending_dcrs');

    Permission::findOrCreate('AccessAllRecords', 'web');
    $rep->givePermissionTo('AccessAllRecords');
    $rep->refresh();

    Sanctum::actingAs($rep);

    $this->getJson('/api/v1/dashboard/today')
        ->assertSuccessful()
        ->assertJsonPath('data.pending_dcrs', 2);
});
