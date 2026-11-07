<?php

use App\Enums\FieldPlanningMode;
use App\Filament\Pages\FlexiblePlanning;
use App\Models\FieldUserHq;
use App\Models\FlexiblePlanEntry;
use App\Models\SalesDcr;
use App\Models\Visit;
use App\Models\VisitPreference;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-08 12:00:00'));
    $this->rep = reportingUser('FlexibleRep');
    $this->other = reportingUser('FlexibleOther');
    foreach (['Create:Visit', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster', 'Manage:FieldPlanningPreference'] as $name) {
        Permission::findOrCreate($name, 'web');
        $this->rep->givePermissionTo($name);
        $this->other->givePermissionTo($name);
    }
    Sanctum::actingAs($this->rep);
    $this->doctor = reportingDoctor($this->rep, 'Planned Doctor');
    $this->settings = app(FieldPlanningSettingsService::class);
    $revision = $this->settings->saveProposal($this->rep, ['mode' => 'flexible', 'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::Flexible), 'reason' => 'Flexible pilot'], 0);
    $this->settings->activate($this->rep, $revision->id, 1);
    VisitPreference::current()->update(['require_gps' => false, 'enforce_geo_fencing' => false, 'field_rules' => [],
        'enable_check_in' => true, 'enable_check_out' => true, 'require_check_in_image' => false,
        'require_check_out_image' => false, 'require_general_visit_image' => false]);
    $this->proposal = ['client_plan_id' => (string) Str::uuid(), 'expected_version' => 0, 'account_id' => $this->doctor->id,
        'date' => today()->toDateString(), 'status' => 'active', 'notes' => 'Weekly intention'];
});
function openFlexibleCall($test, array $entry, array $overrides = [])
{
    return $test->postJson('/api/v1/visits/direct', [...[
        'client_call_id' => (string) Str::uuid(), 'company_id' => $test->doctor->id,
        'date' => $entry['date'], 'flexible_plan_entry_id' => $entry['id'], 'flexible_plan_version' => $entry['version'],
    ], ...$overrides]);
}
it('saves optional intentions idempotently without creating a visit or DCR', function () {
    $first = $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful()->assertJsonPath('data.version', 1)->json('data.id');
    $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful()->assertJsonPath('data.id', $first);
    expect(FlexiblePlanEntry::query()->count())->toBe(1)->and(Visit::query()->count())->toBe(0)->and(SalesDcr::query()->count())->toBe(0);
    $this->getJson('/api/v1/me')->assertJsonPath('data.field_planning.supports_flexible_plans', true)->assertJsonPath('data.field_planning.supports_direct_calls', true);
});
it('versions edits and cancellation and rejects stale writes', function () {
    $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful();
    $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'expected_version' => 1, 'notes' => 'Changed'])->assertSuccessful()->assertJsonPath('data.version', 2);
    $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'expected_version' => 1, 'notes' => 'Stale'])->assertConflict();
    $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'expected_version' => 2, 'status' => 'cancelled'])->assertSuccessful()->assertJsonPath('data.execution_status', 'cancelled');
    expect(FlexiblePlanEntry::query()->sole()->history)->toHaveCount(3);
});
it('executes an intention through the usual call lifecycle without MTP approval', function () {
    $entry = $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful()->json('data');
    $id = openFlexibleCall($this, $entry)->assertSuccessful()->assertJsonPath('data.visit_type', 'planned')->assertJsonPath('data.sales_tour_plan_detail_id', null)->json('data.id');
    $this->postJson('/api/v1/visits/'.$id.'/check-in', ['latitude' => 22, 'longitude' => 73])->assertSuccessful();
    $this->postJson('/api/v1/visits/'.$id.'/check-out', ['latitude' => 22, 'longitude' => 73])->assertSuccessful();
    $this->getJson('/api/v1/flexible-plans?start='.today()->toDateString())->assertSuccessful()->assertJsonPath('data.completed', 1);
    $this->getJson('/api/v1/field-coverage?month='.today()->format('Y-m'))->assertSuccessful()->assertJsonPath('data.customers.0.pending_calls', 1);
    $dcrId = Visit::query()->findOrFail($id)->sales_dcr_id;
    $this->postJson('/api/v1/dcrs/'.$dcrId.'/submit')->assertSuccessful();
    $this->getJson('/api/v1/field-coverage?month='.today()->format('Y-m'))->assertSuccessful()->assertJsonPath('data.customers.0.completed_calls', 1);
    $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'expected_version' => 1, 'status' => 'cancelled'])->assertUnprocessable();
});
it('rejects stale cancelled wrong customer and wrong date execution targets', function () {
    $entry = $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful()->json('data');
    $updated = $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'expected_version' => 1, 'notes' => 'New notes'])->assertSuccessful()->json('data');
    openFlexibleCall($this, $entry)->assertConflict();
    openFlexibleCall($this, $updated, ['date' => today()->addDay()->toDateString()])->assertUnprocessable();
    $otherDoctor = reportingDoctor($this->rep, 'Different doctor');
    openFlexibleCall($this, $updated, ['company_id' => $otherDoctor->id])->assertConflict();
    $cancelled = $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'expected_version' => 2, 'status' => 'cancelled'])->assertSuccessful()->json('data');
    openFlexibleCall($this, $cancelled)->assertConflict();
    expect(Visit::query()->count())->toBe(0);
});
it('enforces own plans customer visibility and protected plan identity', function () {
    $entry = $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful()->json('data');
    Sanctum::actingAs($this->other);
    $this->getJson('/api/v1/flexible-plans?user_id='.$this->rep->id)->assertForbidden();
    openFlexibleCall($this, $entry)->assertNotFound();
    $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertNotFound();
});
it('requires reasons for unplanned calls and keeps them separate from intentions', function () {
    $call = ['client_call_id' => (string) Str::uuid(), 'company_id' => $this->doctor->id, 'date' => today()->toDateString()];
    $this->postJson('/api/v1/visits/direct', $call)->assertUnprocessable();
    $id = $this->postJson('/api/v1/visits/direct', [...$call, 'unplanned_reason' => 'Doctor requested a call'])->assertSuccessful()->json('data.id');
    $this->postJson('/api/v1/visits/'.$id.'/check-in', ['latitude' => 22, 'longitude' => 73])->assertSuccessful();
    $this->getJson('/api/v1/flexible-plans?start='.today()->toDateString())->assertSuccessful()->assertJsonPath('data.intended', 0)->assertJsonCount(1, 'data.unplanned_visits');
});
it('reports missed intentions and prevents retrospective planning', function () {
    $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful();
    $this->travelTo(now()->addDay());
    $this->getJson('/api/v1/flexible-plans?start=2026-11-08')->assertSuccessful()->assertJsonPath('data.missed', 1);
    $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'expected_version' => 1, 'status' => 'cancelled'])->assertUnprocessable();
    $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'client_plan_id' => (string) Str::uuid()])->assertUnprocessable();
});
it('rejects a patch outside the selected customer', function () {
    $patch = reportingPatch(reportingTerritory('Patch territory'), 'Other patch');
    $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'patch_id' => $patch->id])->assertUnprocessable();
});
it('renders the Admin planner saves a draft and reviews the week', function () {
    $this->actingAs($this->rep);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(FlexiblePlanning::class)->fillForm($this->proposal)->call('save')->assertHasNoFormErrors()->assertNotified()->assertSee('Planned Doctor');
});

it('keeps started but incomplete past intentions in the missed list', function () {
    $entry = $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful()->json('data');
    openFlexibleCall($this, $entry)->assertSuccessful();
    $this->travelTo(now()->addDay());
    $this->getJson('/api/v1/flexible-plans?start=2026-11-08')->assertSuccessful()->assertJsonPath('data.missed', 1);
});
it('prevents duplicate daily intentions and retroactive relinking of captured calls', function () {
    $entry = $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful()->json('data');
    $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'client_plan_id' => (string) Str::uuid()])->assertConflict();
    $id = openFlexibleCall($this, $entry)->assertSuccessful()->json('data.id');
    $visit = Visit::query()->findOrFail($id);
    expect(fn () => $visit->update(['flexible_plan_entry_id' => null, 'flexible_plan_version' => null, 'visit_type' => 'unplanned', 'unplanned_reason' => 'Retag']))
        ->toThrow(ValidationException::class);
});

it('checks the selected patch again before starting an intention', function () {
    $patch = reportingPatch(reportingTerritory('Flexible territory'), 'Flexible patch');
    $this->doctor->patches()->attach($patch->id);
    $entry = $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'patch_id' => $patch->id])->assertSuccessful()->json('data');
    $this->doctor->patches()->detach($patch->id);
    openFlexibleCall($this, $entry)->assertConflict();
    $updated = $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'expected_version' => 1, 'patch_id' => null])->assertSuccessful()->json('data');
    openFlexibleCall($this, $updated)->assertSuccessful();
});
it('filters the Admin team review by current HQ without granting write access to other plans', function () {
    Permission::findOrCreate('Manage:FieldTeam', 'web');
    $this->rep->givePermissionTo('Manage:FieldTeam');
    $one = reportingLocation('First HQ');
    $two = reportingLocation('Second HQ');
    FieldUserHq::query()->create(['user_id' => $this->rep->id, 'location_master_id' => $one->id, 'updated_by' => $this->rep->id]);
    FieldUserHq::query()->create(['user_id' => $this->other->id, 'location_master_id' => $two->id, 'updated_by' => $this->rep->id]);
    $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful();
    Sanctum::actingAs($this->other);
    $doctor = reportingDoctor($this->other, 'Other HQ doctor');
    $entry = $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'client_plan_id' => (string) Str::uuid(), 'account_id' => $doctor->id])->assertSuccessful()->json('data');
    $this->actingAs($this->rep);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $page = Livewire::test(FlexiblePlanning::class)->set('hqFilter', $two->id)->assertSet('userId', $this->other->id)->assertSee('Other HQ doctor');
    expect(fn () => $page->call('cancelIntention', $entry['id'], $entry['version']))->toThrow(ModelNotFoundException::class);
    expect(FlexiblePlanEntry::query()->findOrFail($entry['id'])->status)->toBe('active');
});
