<?php

use App\Events\ApprovalStatusChanged;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\JobRole;
use App\Models\Position;
use App\Models\SalesTourPlan;
use App\Models\Visit;
use App\Models\VisitPurpose;
use App\Models\VisitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function fieldMtpFixture(): array
{
    Event::fake([ApprovalStatusChanged::class]);
    $territory = reportingTerritory('MTP Territory');
    $users = [];
    $roles = [];
    foreach (['Rep', 'Manager', 'Director'] as $index => $name) {
        $role = JobRole::create(['name' => $name, 'code' => 'MTP'.$index, 'level' => $index + 1]);
        $user = reportingUser('MTP'.$name);
        $position = Position::create(['name' => $name, 'code' => 'MTPPOS'.$index, 'job_role_id' => $role->id]);
        $position->territories()->attach($territory->id);
        $user->employee->positions()->attach($position->id, ['is_primary' => true]);
        $users[] = $user->fresh();
        $roles[] = $role;
    }
    foreach (['Create:SalesTourPlan', 'Update:SalesTourPlan'] as $name) {
        $users[0]->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    ApprovalSetting::query()->update(['enabled_modules' => ['SalesTourPlan']]);
    if (! ApprovalSetting::query()->exists()) {
        ApprovalSetting::create(['enabled_modules' => ['SalesTourPlan']]);
    }
    $flow = ApprovalFlow::create(['module' => 'SalesTourPlan', 'territory_id' => $territory->id, 'active' => true]);
    foreach ([1, 2] as $index) {
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'step_order' => $index, 'job_role_id' => $roles[$index]->id, 'territory_scope' => 'self']);
    }
    $patch = reportingPatch($territory, 'MTP Patch');
    $type = VisitType::create(['name' => 'Field Work', 'code' => 'MTPFIELD', 'is_active' => true]);
    $purpose = VisitPurpose::create(['name' => 'Doctor calls', 'code' => 'MTPDOC', 'is_active' => true, 'visit_type_id' => $type->id]);
    Sanctum::actingAs($users[0]);
    $body = ['month' => '2026-10', 'details' => [[
        'date' => '2026-10-01', 'territory_id' => $territory->id, 'patch_ids' => [$patch->id],
        'visit_type_id' => $type->id, 'visit_purpose_ids' => [$purpose->id], 'joint_with' => [], 'remarks' => 'Morning route',
    ]]];

    return ['rep' => $users[0], 'manager' => $users[1], 'director' => $users[2], 'body' => $body, 'territory' => $territory];
}

it('creates and edits an owned plan and rejects duplicate months and stale drafts', function () {
    $f = fieldMtpFixture();
    $plan = $this->postJson('/api/v1/monthly-tour-plans', $f['body'])->assertSuccessful()->assertJsonPath('data.details.0.patch_ids.0', $f['body']['details'][0]['patch_ids'][0])->json('data');
    $this->postJson('/api/v1/monthly-tour-plans', $f['body'])->assertConflict();
    $body = [...$f['body'], 'revision' => $plan['revision']];
    $body['details'][0]['remarks'] = 'Changed route';
    $this->putJson('/api/v1/monthly-tour-plans/'.$plan['id'], $body)->assertSuccessful()->assertJsonPath('data.details.0.remarks', 'Changed route');
    $this->putJson('/api/v1/monthly-tour-plans/'.$plan['id'], $body)->assertConflict();
    $this->getJson('/api/v1/monthly-tour-plans?month=2026-10')->assertSuccessful()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/monthly-tour-plans?month=2026-11')->assertJsonCount(0, 'data');
});

it('allows changes to another day while keeping a day with visits unchanged', function () {
    $f = fieldMtpFixture();
    $body = $f['body'];
    $body['details'][] = [...$body['details'][0], 'date' => '2026-10-02', 'remarks' => 'Second route'];
    $plan = $this->postJson('/api/v1/monthly-tour-plans', $body)->assertSuccessful()->json('data');
    $firstDay = SalesTourPlan::findOrFail($plan['id'])->details()->whereDate('date', '2026-10-01')->firstOrFail();
    Visit::withoutEvents(fn () => Visit::create([
        'document_number' => 'MTP-VISIT-1',
        'employee_id' => $f['rep']->id,
        'territory_id' => $f['territory']->id,
        'patch_id' => $body['details'][0]['patch_ids'][0],
        'sales_tour_plan_id' => $plan['id'],
        'sales_tour_plan_detail_id' => $firstDay->id,
        'visit_date' => '2026-10-01',
        'visit_status' => 'started',
    ]));

    $body['revision'] = $this->getJson('/api/v1/monthly-tour-plans/'.$plan['id'])->assertJsonPath('data.details.0.has_visits', true)->json('data.revision');
    $body['details'][1]['remarks'] = 'Updated second route';
    $updated = $this->putJson('/api/v1/monthly-tour-plans/'.$plan['id'], $body)
        ->assertSuccessful()->assertJsonPath('data.details.1.remarks', 'Updated second route')->json('data');

    $body['revision'] = $updated['revision'];
    $body['details'][0]['remarks'] = 'Changed visited route';
    $this->putJson('/api/v1/monthly-tour-plans/'.$plan['id'], $body)->assertUnprocessable();
});

it('hides other users plans and enforces create permission', function () {
    $f = fieldMtpFixture();
    $plan = $this->postJson('/api/v1/monthly-tour-plans', $f['body'])->json('data');
    Sanctum::actingAs($f['manager']);
    $this->getJson('/api/v1/monthly-tour-plans/'.$plan['id'])->assertNotFound();
    $this->getJson('/api/v1/monthly-tour-plans?month=2026-10')->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/monthly-tour-plans', $f['body'])->assertForbidden();
});

it('validates dates territories and purpose relationships without partial writes', function () {
    $f = fieldMtpFixture();
    $invalid = $f['body'];
    $invalid['details'][0]['date'] = '2026-11-01';
    $this->postJson('/api/v1/monthly-tour-plans', $invalid)->assertUnprocessable();
    $invalid = $f['body'];
    $invalid['details'][0]['territory_id'] = reportingTerritory('Other')->id;
    $this->postJson('/api/v1/monthly-tour-plans', $invalid)->assertUnprocessable();
    $invalid = $f['body'];
    $invalid['details'][0]['visit_type_id'] = null;
    $this->postJson('/api/v1/monthly-tour-plans', $invalid)->assertUnprocessable();
    $invalid = $f['body'];
    $invalid['details'][] = $invalid['details'][0];
    $this->postJson('/api/v1/monthly-tour-plans', $invalid)->assertUnprocessable();
    expect(SalesTourPlan::count())->toBe(0);
});

it('submits locks edits and requires sequential assigned approvals', function () {
    $f = fieldMtpFixture();
    $plan = $this->postJson('/api/v1/monthly-tour-plans', $f['body'])->json('data');
    $path = '/api/v1/monthly-tour-plans/'.$plan['id'];
    $submitted = $this->postJson($path.'/submit', ['revision' => $plan['revision']])->assertSuccessful()->assertJsonPath('data.approval_status', 'pending')->json('data');
    $this->putJson($path, [...$f['body'], 'revision' => $submitted['revision']])->assertForbidden();
    $this->postJson($path.'/approve', ['revision' => $submitted['revision']])->assertForbidden();
    Sanctum::actingAs($f['director']);
    $this->getJson('/api/v1/monthly-tour-plans/inbox?month=2026-10')->assertJsonCount(0, 'data');
    $this->postJson($path.'/approve', ['revision' => $submitted['revision']])->assertNotFound();
    Sanctum::actingAs($f['manager']);
    $this->getJson('/api/v1/monthly-tour-plans/inbox?month=2026-10')->assertSuccessful()->assertJsonCount(1, 'data');
    $next = $this->postJson($path.'/approve', ['revision' => $submitted['revision'], 'comments' => 'Checked'])->assertSuccessful()->assertJsonPath('data.approval_status', 'pending')->json('data');
    Sanctum::actingAs($f['director']);
    $this->getJson('/api/v1/monthly-tour-plans/inbox?month=2026-10')->assertJsonCount(1, 'data');
    $this->postJson($path.'/approve', ['revision' => $next['revision']])->assertSuccessful()->assertJsonPath('data.approval_status', 'approved');
    expect(SalesTourPlan::find($plan['id'])->approved_by)->toBe($f['director']->id);
    Event::assertDispatched(ApprovalStatusChanged::class);
});

it('requires rejection remarks and allows corrected resubmission', function () {
    $f = fieldMtpFixture();
    $plan = $this->postJson('/api/v1/monthly-tour-plans', $f['body'])->json('data');
    $path = '/api/v1/monthly-tour-plans/'.$plan['id'];
    $submitted = $this->postJson($path.'/submit', ['revision' => $plan['revision']])->assertSuccessful()->json('data');
    Sanctum::actingAs($f['manager']);
    $this->postJson($path.'/reject', ['revision' => $submitted['revision']])->assertUnprocessable();
    $rejected = $this->postJson($path.'/reject', ['revision' => $submitted['revision'], 'comments' => 'Revise route'])->assertSuccessful()->assertJsonPath('data.approval_status', 'rejected')->json('data');
    Sanctum::actingAs($f['rep']);
    $body = [...$f['body'], 'revision' => $rejected['revision']];
    $body['details'][0]['remarks'] = 'Revised route';
    $updated = $this->putJson($path, $body)->assertSuccessful()->json('data');
    $this->postJson($path.'/submit', ['revision' => $updated['revision']])->assertSuccessful()->assertJsonPath('data.approval_status', 'pending');
});

it('keeps the draft intact when no approval flow is configured', function () {
    $f = fieldMtpFixture();
    ApprovalSetting::query()->update(['enabled_modules' => []]);
    $plan = $this->postJson('/api/v1/monthly-tour-plans', $f['body'])->json('data');
    $this->postJson('/api/v1/monthly-tour-plans/'.$plan['id'].'/submit', ['revision' => $plan['revision']])->assertUnprocessable();
    expect(SalesTourPlan::find($plan['id'])->approval_status)->toBe('draft');
});

it('does not submit an empty generated month and validates missing nullable fields', function () {
    $f = fieldMtpFixture();
    $body = $f['body'];
    unset($body['details'][0]['territory_id']);
    $this->postJson('/api/v1/monthly-tour-plans', $body)->assertUnprocessable();
    $body = $f['body'];
    $body['details'][0]['visit_type_id'] = null;
    $body['details'][0]['visit_purpose_ids'] = [];
    $body['details'][0]['remarks'] = null;
    $plan = $this->postJson('/api/v1/monthly-tour-plans', $body)->assertSuccessful()->json('data');
    $this->postJson('/api/v1/monthly-tour-plans/'.$plan['id'].'/submit', ['revision' => $plan['revision']])->assertUnprocessable();
});
