<?php

use App\Enums\FieldPlanningMode;
use App\Models\FieldPlanningRevision;
use App\Models\FieldUserHq;
use App\Models\JobRole;
use App\Models\LocationMaster;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\Field\FieldPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = grantPagePermissions(reportingUser('Assignment Manager'), 'Manage:FieldTeam', 'Update:AccountMaster', 'ViewOwn:AccountMaster');
    $this->rep = User::factory()->create(['name' => 'Incoming Representative']);
    $this->hq = reportingLocation('Market HQ');
    $this->url = '/api/v1/field-team-assignments';
    Sanctum::actingAs($this->manager);
});

function setAssignmentPlanningMode(User $actor, FieldPlanningMode $mode): void
{
    $revision = FieldPlanningRevision::query()->create([
        'version' => 1,
        'mode' => $mode,
        'rules' => app(FieldPlanningService::class)->preset($mode),
        'reason' => 'Assignment API test',
        'created_by' => $actor->id,
    ]);
    Organization::query()->create([
        'name' => 'Assignment Planning',
        'metadata' => ['company_setup' => ['planning_revision_id' => $revision->id]],
    ]);
}

it('offers the negotiated destination to managers and rechecks permission on direct requests', function () {
    $menu = '/api/v1/mobile-navigation';
    $this->getJson($menu)->assertOk()->assertJsonMissing(['key' => 'g03-06']);
    $this->getJson($menu.'?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g03-06', 'screen_key' => 'field-team-assignments']);
    $this->getJson($this->url.'/representatives?search=Incoming')->assertOk()
        ->assertJsonPath('data.0.id', $this->rep->id);
    $this->manager->revokePermissionTo('Manage:FieldTeam');
    $this->getJson($menu.'?destinations=standalone')->assertOk()->assertJsonMissing(['key' => 'g03-06']);
    foreach (['representatives', 'representatives/'.$this->rep->id, 'representatives/'.$this->rep->id.'/hqs', 'customers'] as $path) {
        $this->getJson($this->url.'/'.$path)->assertForbidden();
    }
    $this->postJson($this->url, [])->assertForbidden();
    $this->postJson($this->url.'/hqs', [])->assertForbidden();
});

it('keeps assignment and call-target history when transferring selected customers', function () {
    $this->travelTo(now()->startOfMonth()->addDays(10));
    $existing = reportingDoctor($this->manager, 'Existing Target');
    $existing->callTargets()->create(['effective_from' => today()->startOfMonth(), 'calls_per_month' => 4]);
    $new = reportingDoctor($this->manager, 'Future Target');
    $nextMonth = today()->addMonthNoOverflow()->startOfMonth();
    $new->callTargets()->create(['effective_from' => $nextMonth, 'calls_per_month' => 9]);
    $unselected = reportingDoctor($this->manager, 'Unselected Customer');

    $this->postJson($this->url, [
        'user_id' => $this->rep->id,
        'location_master_id' => $this->hq->id,
        'account_ids' => [$existing->id, $new->id],
        'calls_per_month' => 0,
    ])->assertOk()->assertJsonPath('data.assigned_customers', 2);

    $this->getJson($this->url.'/representatives/'.$this->rep->id)->assertOk()
        ->assertJsonPath('data.current_hq.id', $this->hq->id)
        ->assertJsonPath('data.current_hq_eligible', true);
    expect($existing->fresh()->owner_id)->toBe($this->rep->id)
        ->and($existing->assignmentHistory()->where('owner_id', $this->manager->id)->sole()->effective_to)->not->toBeNull()
        ->and($existing->assignmentHistory()->whereNull('effective_to')->sole()->owner_id)->toBe($this->rep->id)
        ->and($existing->callTargets()->sole()->calls_per_month)->toBe(4)
        ->and($unselected->fresh()->owner_id)->toBe($this->manager->id);
    $seeded = $new->callTargets()->orderBy('effective_from')->firstOrFail();
    expect($seeded->calls_per_month)->toBe(0)
        ->and($seeded->effective_to->toDateString())->toBe($nextMonth->copy()->subDay()->toDateString())
        ->and($new->callTargets()->count())->toBe(2);
});

it('scopes customer options and rejects a mixed selection before saving anything', function () {
    $visible = reportingDoctor($this->manager, 'Market Visible');
    Sanctum::actingAs($this->rep);
    $hidden = reportingDoctor($this->rep, 'Market Hidden');
    Sanctum::actingAs($this->manager);

    $this->getJson($this->url.'/customers?search=Market')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
    $payload = [
        'user_id' => $this->rep->id,
        'location_master_id' => $this->hq->id,
        'account_ids' => [$visible->id, $hidden->id],
        'calls_per_month' => 2,
    ];
    $this->postJson($this->url, $payload)->assertNotFound();
    $this->manager->revokePermissionTo('Update:AccountMaster');
    $this->getJson($this->url.'/customers')->assertForbidden();
    $this->postJson($this->url, [...$payload, 'account_ids' => [$visible->id]])->assertForbidden();

    expect(FieldUserHq::query()->exists())->toBeFalse()
        ->and($visible->fresh()->owner_id)->toBe($this->manager->id)
        ->and($visible->callTargets()->exists())->toBeFalse();
});

it('requires an HQ outside Direct DCR and preserves the current HQ when omitted in Direct DCR', function () {
    $payload = ['user_id' => $this->rep->id, 'account_ids' => [], 'calls_per_month' => 2];
    $this->postJson($this->url, $payload)->assertUnprocessable()->assertJsonValidationErrors('location_master_id');

    $hq = FieldUserHq::query()->create(['user_id' => $this->rep->id, 'location_master_id' => $this->hq->id, 'updated_by' => $this->manager->id]);
    setAssignmentPlanningMode($this->manager, FieldPlanningMode::DirectDcr);

    $this->postJson($this->url, $payload)->assertOk()->assertJsonPath('data.hq_id', $hq->id);
    expect($hq->fresh()->location_master_id)->toBe($this->hq->id);
});

it('allows permitted HQ creation and enforces linked HQ choices in Formal MTP', function () {
    $body = ['name' => 'New Market HQ'];
    $this->postJson($this->url.'/hqs', $body)->assertForbidden();
    grantPagePermissions($this->manager, 'Create:LocationMaster');
    $created = $this->postJson($this->url.'/hqs', $body)->assertCreated()->json('data.id');
    $newHq = LocationMaster::query()->findOrFail($created);
    expect((bool) $newHq->is_active)->toBeTrue()
        ->and($newHq->location_code)->not->toBeEmpty()
        ->and($newHq->location_code)->not->toBe($this->hq->location_code);
    $this->postJson($this->url.'/hqs', [])->assertUnprocessable()->assertJsonValidationErrors('name');

    setAssignmentPlanningMode($this->manager, FieldPlanningMode::FormalMtp);
    $rep = reportingUser('Formal Representative');
    $territory = reportingTerritory('Formal Market');
    $role = JobRole::query()->create(['name' => 'Rep', 'code' => 'REP-G0306', 'level' => 1]);
    $position = Position::query()->create(['name' => 'Formal Rep', 'code' => 'POS-G0306', 'job_role_id' => $role->id]);
    $rep->employee->positions()->attach($position->id, ['is_primary' => true]);
    $position->territories()->attach($territory->id);
    $this->hq->territories()->attach($territory->id);
    $options = $this->url.'/representatives/'.$rep->id;

    $this->getJson($options)->assertOk()->assertJsonPath('data.can_create_hq', false);
    $this->getJson($options.'/hqs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->hq->id);
    $this->postJson($this->url.'/hqs', ['name' => 'Another HQ'])->assertUnprocessable();
    $this->postJson($this->url, [
        'user_id' => $rep->id, 'location_master_id' => $created, 'account_ids' => [], 'calls_per_month' => 2,
    ])->assertUnprocessable()->assertJsonValidationErrors('location_master_id');
});
