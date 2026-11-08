<?php

use App\Enums\FieldPlanningMode;
use App\Models\NumberSeries;
use App\Models\Visit;
use App\Models\VisitPreference;
use App\Services\Field\FieldDcrService;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use App\Services\Field\FieldVisitService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
beforeEach(function () {
    $this->rep = reportingUser('DirectRep');
    foreach (['Create:Visit', 'Manage:FieldPlanningPreference', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $this->rep->givePermissionTo($permission);
    }
    Sanctum::actingAs($this->rep);
    $this->doctor = reportingDoctor($this->rep, 'Direct doctor');
    $this->settings = app(FieldPlanningSettingsService::class);
    $this->revision = $this->settings->saveProposal($this->rep, [...$this->settings->initialProposal(), 'reason' => 'Direct pilot'], 0);
    $this->settings->activate($this->rep, $this->revision->id, 1);
    VisitPreference::current()->update(['require_gps' => false, 'enforce_geo_fencing' => false, 'field_rules' => []]);
    $this->call = ['client_call_id' => (string) Str::uuid(), 'company_id' => $this->doctor->id, 'date' => today()->toDateString()];
});
it('opens a direct call attaches the owned DCR and retries without duplicates', function () {
    $id = $this->postJson('/api/v1/visits/direct', $this->call)->assertSuccessful()->assertJsonPath('data.territory_id', null)->json('data.id');
    $this->postJson('/api/v1/visits/direct', $this->call)->assertSuccessful()->assertJsonPath('data.id', $id);
    $visit = Visit::query()->findOrFail($id);
    expect($visit->salesDcr->user_id)->toBe($this->rep->id)
        ->and($visit->planning_revision_id)->toBe($this->revision->id)
        ->and(Visit::query()->count())->toBe(1);
    $this->getJson('/api/v1/me')->assertJsonPath('data.field_planning.supports_direct_calls', true);
});
it('rejects UUID reuse with a changed customer', function () {
    $this->postJson('/api/v1/visits/direct', $this->call)->assertSuccessful();
    $other = reportingDoctor($this->rep, 'Other doctor');
    $this->postJson('/api/v1/visits/direct', [...$this->call, 'company_id' => $other->id])->assertConflict();
});
it('enforces account visibility and creation permission', function () {
    $this->postJson('/api/v1/visits/direct', [...$this->call, 'company_id' => 999999])->assertNotFound();
    $this->rep->revokePermissionTo('Create:Visit');
    $this->postJson('/api/v1/visits/direct', $this->call)->assertForbidden();
});
it('enforces active formal policy on model writes used by Admin and API', function () {
    $formal = $this->settings->saveProposal($this->rep, [
        'mode' => 'formal_mtp', 'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::FormalMtp),
        'reason' => 'Formal pilot', 'proposed_effective_on' => null,
    ], 1);
    $this->settings->activate($this->rep, $formal->id, 2);
    $this->postJson('/api/v1/visits/direct', $this->call)->assertUnprocessable();
    expect(fn () => Visit::query()->create(['document_number' => 'BYPASS', 'employee_id' => $this->rep->id,
        'visit_date' => today(), 'visit_type' => 'unplanned']))->toThrow(ValidationException::class);
});
it('accepts direct journal events and protects retry identity', function () {
    $event = (string) Str::uuid();
    $packet = ['event_id' => $event, 'action' => 'check_in', 'target' => $this->call,
        'captured_at' => now()->format('Y-m-d\TH:i:sP'), 'payload' => ['latitude' => 22.3, 'longitude' => 73.1]];
    $this->postJson('/api/v1/field-visit-events', $packet, ['Idempotency-Key' => $event])->assertSuccessful();
    $this->postJson('/api/v1/field-visit-events', $packet, ['Idempotency-Key' => $event])->assertSuccessful();
    expect(Visit::query()->count())->toBe(1);
});
it('activates flexible plans and honors future effective dates', function () {
    $next = $this->settings->saveProposal($this->rep, [
        'mode' => 'formal_mtp', 'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::FormalMtp),
        'reason' => 'Next week', 'proposed_effective_on' => today()->addWeek()->toDateString(),
    ], 1);
    $this->settings->activate($this->rep, $next->id, 2);
    expect(app(FieldPlanningService::class)->active()->id)->toBe($this->revision->id);
    $flex = $this->settings->saveProposal($this->rep, ['mode' => 'flexible',
        'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::Flexible), 'reason' => 'Future flexibility'], 2);
    $this->settings->activate($this->rep, $flex->id, 3);
    expect(app(FieldPlanningService::class)->active()->mode)->toBe(FieldPlanningMode::Flexible);
});

it('completes direct visits calculates patchless travel and submits the DCR', function () {
    VisitPreference::current()->update(['enable_check_in' => true, 'enable_check_out' => true,
        'require_check_in_image' => false, 'require_check_out_image' => false, 'require_general_visit_image' => false]);
    $first = $this->postJson('/api/v1/visits/direct', $this->call)->assertSuccessful()->json('data.id');
    $this->postJson('/api/v1/visits/'.$first.'/check-in', ['latitude' => 22, 'longitude' => 73])->assertSuccessful();
    $this->postJson('/api/v1/visits/'.$first.'/check-out', ['latitude' => 22, 'longitude' => 73])->assertSuccessful();
    $other = reportingDoctor($this->rep, 'Second direct doctor');
    $second = $this->postJson('/api/v1/visits/direct', [...$this->call, 'client_call_id' => (string) Str::uuid(), 'company_id' => $other->id])
        ->assertSuccessful()->json('data.id');
    $this->postJson('/api/v1/visits/'.$second.'/check-in', ['latitude' => 22, 'longitude' => 73])->assertSuccessful();
    $this->postJson('/api/v1/visits/'.$second.'/check-out', ['latitude' => 22, 'longitude' => 73])->assertSuccessful();
    $dcr = Visit::query()->findOrFail($first)->salesDcr;
    expect($dcr->travelSegments()->count())->toBe(1);
    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit')->assertSuccessful();
    $this->postJson('/api/v1/visits/direct', [...$this->call, 'client_call_id' => (string) Str::uuid()])->assertUnprocessable();
});

it('blocks Admin model creation when its automatically attached DCR is locked', function () {
    $dcr = app(FieldDcrService::class)->forDate($this->rep);
    $dcr->forceFill(['approval_status' => 'approved'])->saveQuietly();
    expect(fn () => Visit::query()->create(['document_number' => 'LOCKED-ADMIN',
        'employee_id' => $this->rep->id, 'visit_date' => today(), 'visit_type' => 'unplanned']))
        ->toThrow(ValidationException::class);
    expect(Visit::query()->count())->toBe(0);
});

it('reserves formatted visit numbers atomically and rolls the counter back when opening fails', function () {
    $series = NumberSeries::query()->create(['model_type' => Visit::class, 'type_master_id' => null,
        'Prefix' => 'VIS-', 'Suffix' => '-X', 'next_number' => 41]);
    $collision = Visit::withoutEvents(fn () => Visit::query()->create(['document_number' => 'VIS-41-X',
        'employee_id' => $this->rep->id, 'visit_date' => today(), 'visit_type' => 'unplanned', 'visit_status' => 'draft']));
    expect(fn () => app(FieldVisitService::class)->openDirect($this->rep, $this->call))->toThrow(QueryException::class);
    expect((int) $series->fresh()->next_number)->toBe(41)
        ->and(Visit::query()->where('client_call_id', $this->call['client_call_id'])->exists())->toBeFalse();
    Visit::query()->whereKey($collision->id)->update(['document_number' => 'SEED-COLLISION']);
    $this->postJson('/api/v1/visits/direct', $this->call)->assertSuccessful()->assertJsonPath('data.document_number', 'VIS-41-X');
    $this->postJson('/api/v1/visits/direct', $this->call)->assertSuccessful()->assertJsonPath('data.document_number', 'VIS-41-X');
    expect((int) $series->fresh()->next_number)->toBe(42);
    $this->postJson('/api/v1/visits/direct', [...$this->call, 'client_call_id' => (string) Str::uuid()])
        ->assertSuccessful()->assertJsonPath('data.document_number', 'VIS-42-X');
    expect((int) $series->fresh()->next_number)->toBe(43);
});
