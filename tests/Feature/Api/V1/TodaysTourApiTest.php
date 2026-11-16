<?php

use App\Filament\Pages\TodaysTour;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists the authenticated users tour plan for today', function () {
    $user = fieldReportingUser('TourApi');
    $territory = reportingTerritory('Tour Terr');
    $patch = reportingPatch($territory, 'Tour Patch');
    $doctor = reportingDoctor($user, 'Dr Tour');
    $patch->companies()->attach($doctor->id);

    $this->actingAs($user);

    $plan = SalesTourPlan::forceCreate([
        'user_id' => $user->id,
        'month' => now()->format('Y-m'),
        'approval_status' => 'approved',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $detail = SalesTourPlanDetail::forceCreate([
        'sales_tour_plan_id' => $plan->id,
        'date' => today(),
        'territory_id' => $territory->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $detail->patches()->attach($patch->id);

    Sanctum::actingAs(grantPagePermissions($user, 'View:TodaysTour', 'Create:Quote', 'ViewAny:Quote', 'View:Quote', 'ViewOwn:Quote'));

    $this->getJson('/api/v1/todays-tour')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $detail->id)
        ->assertJsonPath('data.0.territory.name', 'Tour Terr')
        ->assertJsonPath('data.0.patches.0.companies.0.name', 'Dr Tour');

    $path = '/api/v1/tour-plan-details/'.$detail->id.'/visits';
    $body = ['patch_id' => $patch->id, 'company_id' => $doctor->id];
    $visit = $this->postJson($path, $body)
        ->assertSuccessful()
        ->assertJsonPath('data.primary_company.name', 'Dr Tour')
        ->json('data');
    $this->postJson($path, $body)->assertSuccessful()->assertJsonPath('data.id', $visit['id']);
    $this->getJson('/api/v1/visits/'.$visit['id'])
        ->assertSuccessful()
        ->assertJsonPath('data.workspace.customer.name', 'Dr Tour');
    $this->postJson('/api/v1/visits/'.$visit['id'].'/documents', ['type' => 'quote'])
        ->assertUnprocessable();
    $doctor->contactDetails()->attach(reportingContact('Tour', 'Buyer')->id);
    $this->postJson('/api/v1/visits/'.$visit['id'].'/documents', ['type' => 'quote'])
        ->assertSuccessful()
        ->assertJsonPath('data.type', 'quote');
    $this->getJson('/api/v1/visits/'.$visit['id'])
        ->assertJsonPath('data.workspace.documents.0.type', 'quote')
        ->assertJsonPath('data.workspace.documents.0.web_path', null)
        ->assertJsonPath('data.workspace.quote_summary.count', 1);
    $user->revokePermissionTo('View:Quote');
    Sanctum::actingAs($user->fresh());
    $this->getJson('/api/v1/visits/'.$visit['id'])
        ->assertJsonPath('data.workspace.documents', [])
        ->assertJsonPath('data.workspace.quote_summary.count', 0);
    $unplannedDoctor = reportingDoctor($user, 'Dr Outside Patch');
    $this->postJson($path, ['patch_id' => $patch->id, 'company_id' => $unplannedDoctor->id])
        ->assertUnprocessable();
    expect(Visit::query()->where('sales_tour_plan_detail_id', $detail->id)->count())->toBe(1);
    $this->getJson('/api/v1/todays-tour')->assertJsonPath('data.0.visits.0.id', $visit['id']);
    $this->postJson($path.'/action', $body + [
        'action' => 'reschedule',
        'reason' => 'Customer requested a later meeting',
        'rescheduled_for' => today()->addDay()->toDateString(),
    ])->assertSuccessful()
        ->assertJsonPath('data.id', $visit['id'])
        ->assertJsonPath('data.visit_status', 'cancelled')
        ->assertJsonPath('data.reschedule_state', 'requested');
    $this->postJson($path, $body)
        ->assertJsonPath('data.id', $visit['id']);
});

it('hides another users tour plan', function () {
    $owner = fieldReportingUser('TourOwner');
    $other = fieldReportingUser('TourOther');
    $territory = reportingTerritory('Hidden Terr');

    $this->actingAs($owner);

    $plan = SalesTourPlan::forceCreate([
        'user_id' => $owner->id,
        'month' => now()->format('Y-m'),
        'approval_status' => 'approved',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $detail = SalesTourPlanDetail::forceCreate([
        'sales_tour_plan_id' => $plan->id,
        'date' => today(),
        'territory_id' => $territory->id,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    Sanctum::actingAs(grantPagePermissions($other, 'View:TodaysTour'));

    $this->getJson('/api/v1/todays-tour')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/tour-plan-details/'.$detail->id.'/visits', [
        'patch_id' => 1, 'company_id' => 1,
    ])->assertNotFound();
});

it('rejects opening another users tour day', function () {
    $owner = fieldReportingUser('TourDayOwner');
    $other = fieldReportingUser('TourDayOther');
    $territory = reportingTerritory('Owned Terr');
    $patch = reportingPatch($territory, 'Owned Patch');
    $doctor = reportingDoctor($owner, 'Dr Owned');
    $patch->companies()->attach($doctor->id);

    $this->actingAs($owner);

    $plan = SalesTourPlan::forceCreate([
        'user_id' => $owner->id,
        'month' => now()->format('Y-m'),
        'approval_status' => 'approved',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $detail = SalesTourPlanDetail::forceCreate([
        'sales_tour_plan_id' => $plan->id,
        'date' => today(),
        'territory_id' => $territory->id,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    $detail->patches()->attach($patch->id);

    Sanctum::actingAs($other);

    $this->postJson('/api/v1/tour-plan-details/'.$detail->id.'/visits', [
        'patch_id' => $patch->id,
        'company_id' => $doctor->id,
    ])->assertNotFound()
        ->assertJsonPath('message', 'Tour day not found.');

    $this->actingAs(grantPagePermissions($other, 'View:TodaysTour'), 'web');
    Livewire::test(TodaysTour::class)
        ->call('openVisit', $detail->id, $plan->id, $territory->id, $patch->id, $doctor->id)
        ->assertNotFound();

    expect(Visit::query()->count())->toBe(0);
});

it('rejects an account outside the planned patch', function () {
    $user = fieldReportingUser('TourPatchScope');
    $territory = reportingTerritory('Patch Terr');
    $patch = reportingPatch($territory, 'Patch Scope');
    $planned = reportingDoctor($user, 'Dr Planned');
    $outside = reportingDoctor($user, 'Dr Unplanned');
    $patch->companies()->attach($planned->id);

    $this->actingAs($user);

    $plan = SalesTourPlan::forceCreate([
        'user_id' => $user->id,
        'month' => now()->format('Y-m'),
        'approval_status' => 'approved',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $detail = SalesTourPlanDetail::forceCreate([
        'sales_tour_plan_id' => $plan->id,
        'date' => today(),
        'territory_id' => $territory->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $detail->patches()->attach($patch->id);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/tour-plan-details/'.$detail->id.'/visits', [
        'patch_id' => $patch->id,
        'company_id' => $outside->id,
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Select an account in a planned patch.');

    expect(Visit::query()->where('sales_tour_plan_detail_id', $detail->id)->count())->toBe(0);
});
