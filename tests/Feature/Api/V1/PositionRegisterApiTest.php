<?php

use App\Filament\Resources\Positions\Pages\CreatePosition;
use App\Filament\Resources\Positions\Pages\EditPosition;
use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Filament\Resources\Positions\Pages\ViewPosition;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\EmpDepartment;
use App\Models\EmpGrade;
use App\Models\EmpJobTitle;
use App\Models\EmploymentDetail;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\TypeMaster;
use App\Services\Approval\ApprovalService;
use App\Services\HR\PositionRegister;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Position editor');
    $this->outside = reportingUser('Hidden position owner');
    foreach (['Position', 'Territory', 'LocationMaster', 'Employee'] as $model) {
        foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny'] as $ability) {
            $this->actor->givePermissionTo(Permission::findOrCreate($ability.':'.$model, 'web'));
        }
        Permission::findOrCreate('OverrideApproval:'.$model, 'web');
    }
    Permission::findOrCreate('AccessAllRecords', 'web');
    Sanctum::actingAs($this->actor);
    $this->role = JobRole::query()->create(['name' => 'Position role', 'code' => 'POSROLE', 'level' => 1]);
    $this->territory = reportingTerritory('Position territory');
    $this->register = app(PositionRegister::class);
    $this->input = ['name' => 'Position one', 'code' => 'POS-001', 'status' => 'active', 'job_role_id' => $this->role->id, 'territory_ids' => [$this->territory->id], 'hq_territory_id' => $this->territory->id, 'org_unit_ids' => [], 'employee_ids' => []];
    $this->position = $this->register->save($this->actor, null, $this->input);
    $this->path = '/api/v1/positions';
    $this->recordPath = $this->path.'/'.$this->position->id;
});

it('negotiates a scoped positions register outside optional HR with separate read grants', function () {
    Organization::query()->create(['name' => 'Position no optional HR', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()->assertJsonFragment(['key' => 'g08-02', 'screen_key' => 'positions']);
    $hidden = Position::withoutEvents(fn () => Position::query()->create(['name' => 'Hidden position', 'code' => 'HIDDENPOS', 'job_role_id' => $this->role->id, 'created_by' => $this->outside->id]));
    $this->getJson($this->path.'?search=Position')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data');
    $this->getJson($this->path.'/'.$hidden->id)->assertNotFound();
    $this->getJson($this->path.'/options/parents?search=Hidden')->assertJsonCount(0, 'data');
    $this->actor->givePermissionTo('AccessAllRecords');
    $this->getJson($this->path.'/'.$hidden->id)->assertOk();
    $this->actor->revokePermissionTo('View:Position');
    $this->getJson($this->recordPath)->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g08-02']);
});

it('creates once with safe fields and rejects duplicate codes stale edits and revoked replay', function () {
    $input = array_replace($this->input, ['name' => 'Another position', 'code' => 'POS-002']);
    $headers = ['Idempotency-Key' => 'cbaa50e2-c672-4080-b1df-767c4b118d79'];
    $response = $this->postJson($this->path, $input + ['created_by' => $this->outside->id, 'approval_status' => 'approved'], $headers)->assertCreated();
    $this->postJson($this->path, $input + ['created_by' => $this->outside->id, 'approval_status' => 'approved'], $headers)->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));
    $record = Position::query()->findOrFail($response->json('data.id'));
    expect($record->created_by)->toBe($this->actor->id)->and($record->approval_status)->not->toBe('approved');
    $this->postJson($this->path, $input)->assertUnprocessable()->assertJsonValidationErrors('code');
    $revision = $this->register->revision($this->position);
    $this->patchJson($this->recordPath, array_replace($this->input, ['name' => 'Updated position', 'revision' => $revision]))->assertOk();
    $this->patchJson($this->recordPath, $this->input + ['revision' => $revision])->assertConflict();
    $this->actor->revokePermissionTo('Create:Position');
    $this->postJson($this->path, $input, $headers)->assertForbidden();
    $this->getJson($this->path.'?sort=untrusted')->assertUnprocessable();
});

it('rejects inaccessible master and territory selections and enforces HQ membership', function () {
    $hidden = JobRole::withoutEvents(fn () => JobRole::query()->create(['name' => 'Hidden role', 'code' => 'HIDDENROLE', 'level' => 2, 'created_by' => $this->outside->id]));
    $other = reportingTerritory('Other territory');
    $this->patchJson($this->recordPath, array_replace($this->input, ['job_role_id' => $hidden->id, 'revision' => $this->register->revision($this->position)]))->assertUnprocessable()->assertJsonValidationErrors('job_role_id');
    $this->patchJson($this->recordPath, array_replace($this->input, ['hq_territory_id' => $other->id, 'revision' => $this->register->revision($this->position)]))->assertUnprocessable()->assertJsonValidationErrors('hq_territory_id');
    $other->forceFill(['created_by' => $this->outside->id])->saveQuietly();
    $this->patchJson($this->recordPath, array_replace($this->input, ['territory_ids' => [$this->territory->id, $other->id], 'revision' => $this->register->revision($this->position)]))->assertUnprocessable()->assertJsonValidationErrors('territory_ids');
    $this->getJson($this->path.'/options/roles?search=Hidden')->assertJsonCount(0, 'data');
});

it('rejects self and descendant reporting cycles and filters parent choices', function () {
    $child = $this->register->save($this->actor, null, array_replace($this->input, ['code' => 'POS-CHILD', 'reports_to_position_id' => $this->position->id]));
    foreach ([$this->position->id, $child->id] as $parent) {
        $this->patchJson($this->recordPath, $this->input + ['reports_to_position_id' => $parent, 'revision' => $this->register->revision($this->position)])->assertUnprocessable()->assertJsonValidationErrors('reports_to_position_id');
    }
    $this->getJson($this->path.'/options/parents?position_id='.$this->position->id)->assertOk()->assertJsonCount(0, 'data');
    expect($this->position->fresh()->reports_to_position_id)->toBeNull();
});

it('validates division units and filters classification territory and organization fields', function () {
    $type = TypeMaster::query()->create(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
    $division = OrganizationalUnit::query()->create(['name' => 'Position division', 'code' => 'POSDIV', 'type_master_id' => $type->id]);
    $other = OrganizationalUnit::query()->create(['name' => 'Other division', 'code' => 'OTHERDIV', 'type_master_id' => $type->id]);
    $department = EmpDepartment::query()->create(['department_name' => 'Position department', 'department_code' => 'POSDEPT']);
    $title = EmpJobTitle::query()->create(['title' => 'Position title']);
    $grade = EmpGrade::query()->create(['grade_name' => 'Position grade']);
    $input = array_replace($this->input, ['division_ou_id' => $division->id, 'org_unit_ids' => [$other->id], 'revision' => $this->register->revision($this->position)]);
    $this->patchJson($this->recordPath, $input)->assertUnprocessable()->assertJsonValidationErrors('org_unit_ids');
    $input = array_replace($input, ['org_unit_ids' => [$division->id], 'department_id' => $department->id, 'job_title_id' => $title->id, 'job_grade_id' => $grade->id, 'status' => 'vacant']);
    $this->patchJson($this->recordPath, $input)->assertOk();
    $this->getJson($this->path.'?'.http_build_query(['division_ou_id' => $division->id, 'org_unit_ids' => [$division->id], 'department_id' => $department->id, 'job_title_id' => $title->id, 'job_grade_id' => $grade->id, 'territory_ids' => [$this->territory->id], 'status' => 'vacant', 'sort' => 'code', 'direction' => 'desc']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->path.'?status=active')->assertJsonCount(0, 'data');
});

it('retains inaccessible existing links without disclosing their labels', function () {
    $hiddenTerritory = reportingTerritory('Secret territory');
    $hiddenTerritory->forceFill(['created_by' => $this->outside->id])->saveQuietly();
    $this->position->territories()->attach($hiddenTerritory->id);
    $this->position->employees()->attach($this->outside->employee->id, ['is_primary' => true]);
    $this->getJson($this->recordPath)->assertOk()->assertJsonMissing(['label' => 'Secret territory'])->assertJsonPath('data.labels.employee_ids', []);
    $this->patchJson($this->recordPath, $this->input + ['revision' => $this->register->revision($this->position)])->assertOk();
    expect($this->position->territories()->whereKey($hiddenTerritory->id)->exists())->toBeTrue()->and($this->position->employees()->whereKey($this->outside->employee->id)->exists())->toBeTrue();
    expect((bool) $this->position->employees()->whereKey($this->outside->employee->id)->first()->pivot->is_primary)->toBeTrue();
});

it('requires employee update grants and editable employees for assignment changes', function () {
    $input = $this->input + ['revision' => $this->register->revision($this->position)];
    $input['employee_ids'] = [$this->actor->employee->id];
    $this->actor->revokePermissionTo('Update:Employee');
    $this->patchJson($this->recordPath, $input)->assertForbidden();
    $this->actor->givePermissionTo('Update:Employee');
    $this->actor->employee->forceFill(['approval_status' => 'approved'])->saveQuietly();
    $this->patchJson($this->recordPath, $input)->assertForbidden();
    $this->actor->givePermissionTo('OverrideApproval:Employee');
    $this->patchJson($this->recordPath, $input)->assertOk();
    $pivot = $this->position->employees()->first()->pivot;
    expect((bool) $pivot->is_primary)->toBeFalse();
    $revision = $this->register->revision($this->position->fresh());
    $this->position->employees()->updateExistingPivot($this->actor->employee->id, ['is_primary' => true]);
    $this->patchJson($this->recordPath, array_replace($input, ['revision' => $revision]))->assertConflict();
});

it('submits position approval and applies decisions locks and audited overrides', function () {
    $manager = reportingUser('Position reviewer');
    $reviewRole = JobRole::query()->create(['name' => 'Position review role', 'code' => 'POSREVIEW', 'level' => 2]);
    $reviewPosition = Position::query()->create(['name' => 'Position review', 'code' => 'POSREVIEW', 'job_role_id' => $reviewRole->id]);
    $manager->employee->positions()->attach($reviewPosition->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [Position::class]]);
    $flow = ApprovalFlow::query()->create(['module' => 'Position', 'active' => true]);
    ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $reviewRole->id, 'territory_scope' => 'all']);
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(EditPosition::class, ['record' => $this->position->id])->callAction('submit')->assertHasNoActionErrors()->assertFormFieldIsDisabled('name');
    $this->position->refresh();
    $this->patchJson($this->recordPath, $this->input + ['revision' => $this->register->revision($this->position)])->assertForbidden();
    app(ApprovalService::class)->reject($this->position->approval, $manager->id, 'Review reporting structure');
    expect($this->position->fresh()->approval_status)->toBe('rejected');
    $this->postJson($this->recordPath.'/actions/submit', ['revision' => $this->register->revision($this->position->fresh())])->assertOk();
    $this->position->refresh();
    app(ApprovalService::class)->approve($this->position->approval, $manager->id);
    expect($this->position->fresh()->approval_status)->toBe('approved');
    $this->getJson($this->recordPath)->assertJsonPath('data.can_edit', false);
    $this->actor->givePermissionTo('OverrideApproval:Position');
    $this->patchJson($this->recordPath, array_replace($this->input, ['name' => 'Approved position edit', 'revision' => $this->register->revision($this->position->fresh())]))->assertOk();
    $this->assertDatabaseHas('approval_overrides', ['model_type' => Position::class, 'model_id' => $this->position->id, 'user_id' => $this->actor->id]);
});

it('guards linked positions and validates the complete bulk selection before deleting', function () {
    $other = $this->register->save($this->actor, null, array_replace($this->input, ['code' => 'POS-BULK']));
    $other->employees()->attach($this->actor->employee->id);
    $this->postJson($this->path.'/records/delete', ['ids' => [$this->position->id, $other->id]])->assertConflict();
    expect($this->position->fresh()->trashed())->toBeFalse();
    $other->employees()->detach();
    $this->territory->forceFill(['reporting_position_id' => $other->id])->save();
    $this->postJson($this->path.'/'.$other->id.'/actions/delete', ['revision' => $this->register->revision($other)])->assertConflict();
    $this->territory->forceFill(['reporting_position_id' => null])->save();
    $this->postJson($this->path.'/records/delete', ['ids' => [$this->position->id, $other->id]])->assertOk();
    $this->getJson($this->recordPath)->assertNotFound();
});

it('shares Admin create edit view and list with the scoped position service', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $input = array_replace($this->input, ['code' => 'POS-ADMIN', 'name' => 'Admin position']);
    Livewire::test(CreatePosition::class)->fillForm($input)->call('create')->assertHasNoFormErrors();
    $record = Position::query()->where('code', 'POS-ADMIN')->sole();
    Livewire::test(EditPosition::class, ['record' => $record->id])->fillForm(['name' => 'Admin position edited'])->call('save')->assertHasNoFormErrors();
    expect($record->fresh()->name)->toBe('Admin position edited');
    Livewire::test(ViewPosition::class, ['record' => $record->id])->assertSuccessful();
    Livewire::test(ListPositions::class)->assertCanSeeTableRecords([$record]);
});

it('treats legacy default pending positions without an approval request as drafts', function () {
    $this->position->forceFill(['approval_status' => 'pending'])->saveQuietly();
    $this->getJson($this->recordPath)->assertOk()->assertJsonPath('data.approval_status', 'draft')->assertJsonPath('data.can_edit', true);
    $this->patchJson($this->recordPath, $this->input + ['revision' => $this->register->revision($this->position)])->assertOk();
    expect($this->position->fresh()->approval_status)->toBe('draft');
});

it('synchronizes reporting after position changes while retaining primary assignment flags', function () {
    $managerPosition = $this->register->save($this->actor, null, array_replace($this->input, ['code' => 'POS-MANAGER', 'name' => 'Manager position']));
    $managerPosition->employees()->attach($this->outside->employee->id);
    $this->position->employees()->attach($this->actor->employee->id, ['is_primary' => true]);
    $employment = EmploymentDetail::query()->create(['employee_id' => $this->actor->employee->id]);
    $input = array_replace($this->input, ['employee_ids' => [$this->actor->employee->id], 'reports_to_position_id' => $managerPosition->id, 'revision' => $this->register->revision($this->position)]);
    $this->patchJson($this->recordPath, $input)->assertOk();
    expect($employment->fresh()->reporting_manager_id)->toBe($this->outside->employee->id);
    expect((bool) $this->position->employees()->whereKey($this->actor->employee->id)->first()->pivot->is_primary)->toBeTrue();
    $input = array_replace($input, ['reports_to_position_id' => null, 'revision' => $this->register->revision($this->position->fresh())]);
    $this->patchJson($this->recordPath, $input)->assertOk();
    expect($employment->fresh()->reporting_manager_id)->toBeNull();
});
