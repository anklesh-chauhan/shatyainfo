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

it('shares scoped Dashboard charts and preferences with Admin for negotiated clients', function () {
    $viewer = reportingUser('Dashboard Viewer');
    $other = reportingUser('Dashboard Unrelated');
    $other->forceFill(['dashboard_widgets' => ['call_status']])->save();
    SalesDcr::forceCreate(['dcr_date' => today(), 'user_id' => $viewer->id, 'approval_status' => 'draft', 'created_by' => $viewer->id]);
    SalesDcr::forceCreate(['dcr_date' => today(), 'user_id' => $other->id, 'approval_status' => 'approved', 'created_by' => $other->id]);

    $this->getJson('/api/v1/dashboard/overview')->assertUnauthorized();
    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/dashboard/overview')->assertForbidden();
    $this->patchJson('/api/v1/dashboard/preferences', ['widgets' => null])->assertForbidden();

    grantPagePermissions($viewer, 'View:Dashboard', 'View:SalesDcr', 'ViewOwn:SalesDcr');
    Sanctum::actingAs($viewer->fresh());
    $this->getJson('/api/v1/mobile-navigation')->assertJsonMissing(['screen_key' => 'dashboard']);
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')
        ->assertJsonFragment(['key' => 'g01-01', 'screen_key' => 'dashboard']);
    $this->patchJson('/api/v1/dashboard/preferences', ['widgets' => ['unknown']])
        ->assertUnprocessable()->assertJsonValidationErrors('widgets.0');
    $this->patchJson('/api/v1/dashboard/preferences', ['widgets' => ['dcr_status']])
        ->assertOk()->assertJsonPath('data.selected', ['dcr_status']);
    $this->getJson('/api/v1/dashboard/overview?period=today')->assertOk()
        ->assertJsonPath('data.period.key', 'today')
        ->assertJsonPath('data.charts.dcr_status.labels', ['Draft'])
        ->assertJsonPath('data.charts.dcr_status.series.0.values', [1])
        ->assertJsonMissingPath('data.charts.call_status');

    $this->patchJson('/api/v1/dashboard/preferences', ['widgets' => []])
        ->assertOk()->assertJsonPath('data.selected', []);
    $this->patchJson('/api/v1/dashboard/preferences', ['widgets' => null])
        ->assertOk()->assertJsonCount(7, 'data.selected');
    $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonCount(7, 'data.charts');

    expect($viewer->fresh()->dashboard_widgets)->toBeNull()
        ->and($other->fresh()->dashboard_widgets)->toBe(['call_status']);
});

it('requires Team access and keeps personal counts scoped even with broad record visibility', function () {
    $viewer = grantPagePermissions(fieldReportingUser('Workspace Rep'), 'View:Dashboard', 'AccessAllRecords');
    $other = fieldReportingUser('Workspace Other');
    $territory = reportingTerritory('Workspace Counting');
    SalesDcr::forceCreate(['dcr_date' => today(), 'territory_id' => $territory->id, 'user_id' => $viewer->id, 'approval_status' => 'draft']);
    SalesDcr::forceCreate(['dcr_date' => today(), 'territory_id' => $territory->id, 'user_id' => $other->id, 'approval_status' => 'pending']);

    $this->getJson('/api/v1/dashboard/workspace')->assertUnauthorized();
    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/dashboard/workspace?view=personal')->assertOk()
        ->assertJsonPath('data.can_view_team', false)
        ->assertJsonPath('data.counts.pending_dcrs', 1)
        ->assertJsonMissingPath('data.counts.pending_approvals')
        ->assertJsonMissingPath('data.counts.punched_in_today');
    $this->getJson('/api/v1/dashboard/workspace?view=team')->assertForbidden();

    grantPagePermissions($viewer, 'Manage:FieldTeam');
    Sanctum::actingAs($viewer->fresh());
    $this->getJson('/api/v1/dashboard/workspace?view=team')->assertOk()
        ->assertJsonPath('data.can_view_team', true)
        ->assertJsonPath('data.counts.pending_dcrs', 2);

    $viewer->revokePermissionTo('AccessAllRecords');
    Sanctum::actingAs($viewer->fresh());
    $this->getJson('/api/v1/dashboard/workspace?view=team')->assertOk()
        ->assertJsonPath('data.counts.pending_dcrs', 1);
    $this->getJson('/api/v1/dashboard/workspace?view=invalid')->assertUnprocessable();
});
