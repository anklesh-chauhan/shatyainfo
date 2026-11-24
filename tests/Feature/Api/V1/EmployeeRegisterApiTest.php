<?php

use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\RelationManagers\ShiftAssignmentsRelationManager;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\Employee;
use App\Models\HrChangeRequest;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\TypeMaster;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\HR\EmployeeRegister;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->actor = reportingUser('Employee register editor');
    $this->outside = reportingUser('Hidden employee owner');
    $models = ['Employee', 'User', 'HrChangeRequest', ...array_map(fn (array $entry): string => class_basename($entry[1]), array_values(EmployeeRegister::SECTIONS))];
    foreach (array_unique($models) as $model) {
        foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny', 'Restore', 'RestoreAny'] as $ability) {
            $this->actor->givePermissionTo(Permission::findOrCreate($ability.':'.$model, 'web'));
        }
        Permission::findOrCreate('OverrideApproval:'.$model, 'web');
    }
    Permission::findOrCreate('AccessAllRecords', 'web');
    Sanctum::actingAs($this->actor);
    $this->register = app(EmployeeRegister::class);
    $this->input = ['employee_id' => 'HR-ONE', 'first_name' => 'Register', 'last_name' => 'Staff', 'email' => 'register-staff@example.test', 'mobile_number' => '9111122222', 'is_active' => true, 'provision_login' => false];
    $this->employee = $this->register->save($this->actor, null, $this->input);
    $this->path = '/api/v1/employees';
    $this->recordPath = $this->path.'/'.$this->employee->id;
});

it('negotiates the scoped employee directory including deleted records independently of optional HR', function () {
    Organization::query()->create(['name' => 'No optional HR', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()->assertJsonFragment(['key' => 'g08-01', 'screen_key' => 'employees']);
    $this->getJson($this->path.'?search=Register')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonFragment(['id' => $this->employee->id]);
    $this->getJson($this->path.'/'.$this->outside->employee->id)->assertNotFound();
    $this->getJson($this->path.'?search=Hidden')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/options/managers?search=Hidden')->assertJsonCount(0, 'data');
    $this->employee->delete();
    $this->getJson($this->path.'?trash=trashed&search=HR-ONE')->assertJsonPath('data.0.trashed', true);
    $this->getJson($this->path.'?trash=active&search=HR-ONE')->assertJsonCount(0, 'data');
    $this->actor->givePermissionTo('AccessAllRecords');
    $this->getJson($this->path.'/'.$this->outside->employee->id)->assertOk();
    $this->actor->revokePermissionTo('View:Employee');
    $this->getJson($this->recordPath)->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g08-01']);
});

it('creates idempotently without implicit login side effects and rejects duplicate identities and stale edits', function () {
    $input = array_replace($this->input, ['employee_id' => 'HR-TWO', 'email' => 'second-staff@example.test', 'mobile_number' => '9111133333']);
    $headers = ['Idempotency-Key' => '98a546b0-eb61-45bc-b29c-ed72c665c41a'];
    $response = $this->postJson($this->path, $input + ['created_by' => $this->outside->id, 'approval_status' => 'approved'], $headers)->assertCreated();
    $this->postJson($this->path, $input + ['created_by' => $this->outside->id, 'approval_status' => 'approved'], $headers)->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));
    $record = Employee::query()->findOrFail($response->json('data.id'));
    expect($record->created_by)->toBe($this->actor->id)->and($record->login_id)->toBeNull();
    expect(User::query()->where('email', $input['email'])->exists())->toBeFalse();
    $this->postJson($this->path, $input)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    $revision = $this->register->revision($record);
    $this->patchJson($this->path.'/'.$record->id, array_replace($input, ['phone_number' => '12345', 'revision' => $revision]))->assertOk();
    $this->patchJson($this->path.'/'.$record->id, $input + ['revision' => $revision])->assertConflict();
    $this->actor->revokePermissionTo('Create:Employee');
    $this->postJson($this->path, $input, $headers)->assertForbidden();
    Notification::assertNothingSent();
});

it('does not steal login identities and explicitly provisions only a new authorized account', function () {
    $this->patchJson($this->recordPath, $this->input + ['login_id' => $this->outside->id, 'revision' => $this->register->revision($this->employee)])->assertUnprocessable()->assertJsonValidationErrors('login_id');
    $user = User::factory()->create(['email' => 'unlinked@example.test']);
    $this->patchJson($this->recordPath, $this->input + ['login_id' => $user->id, 'revision' => $this->register->revision($this->employee)])->assertOk();
    expect($user->fresh()->employee->id)->toBe($this->employee->id);
    $input = array_replace($this->input, ['employee_id' => 'HR-LOGIN', 'email' => 'provisioned@example.test', 'mobile_number' => '9111144444', 'provision_login' => true]);
    $result = $this->postJson($this->path, $input)->assertCreated();
    $new = Employee::query()->findOrFail($result->json('data.id'));
    expect($new->login_id)->toBe(User::query()->where('email', 'provisioned@example.test')->sole()->id);
    $this->actor->revokePermissionTo('Create:User');
    $this->postJson($this->path, array_replace($input, ['employee_id' => 'HR-DENIED', 'email' => 'denied@example.test', 'mobile_number' => '9111155555']))->assertForbidden();
    expect(Employee::query()->where('employee_id', 'HR-DENIED')->exists())->toBeFalse();
});

it('maintains parent-owned employee sections with permission checks and revisions', function (string $section, array $values) {
    if ($section === 'shifts') {
        $shift = reportingShift('Directory day');
        $shift->forceFill(['created_by' => $this->actor->id])->saveQuietly();
        $values['shift_master_id'] = $shift->id;
    }
    if ($section === 'positions') {
        $values['position_id'] = Position::query()->create(['name' => 'Directory position', 'code' => 'DIRPOS', 'job_role_id' => JobRole::query()->create(['name' => 'Directory role', 'code' => 'DIRROLE', 'level' => 1])->id])->id;
    }
    $path = $this->recordPath.'/sections/'.$section;
    $this->postJson($path, $values + ['revision' => $this->register->revision($this->employee)])->assertOk();
    $response = $this->getJson($path)->assertOk()->assertJsonCount(1, 'data');
    $rowId = $response->json('data.0.id');
    $revision = $response->json('revision');
    $this->patchJson($path.'/'.$rowId, $values + ['revision' => $revision])->assertOk();
    $this->patchJson($this->path.'/'.$this->actor->employee->id.'/sections/'.$section.'/'.$rowId, $values + ['revision' => $this->register->revision($this->actor->employee->fresh())])->assertNotFound();
    $this->actor->revokePermissionTo('View:'.class_basename(EmployeeRegister::SECTIONS[$section][1]));
    $this->getJson($path)->assertForbidden();
})->with([
    'employment' => ['employment', ['employment_type' => 'Permanent', 'org_unit_ids' => []]],
    'professional tax' => ['professional-tax', ['pt_flag' => true, 'pt_no' => 'PT-1', 'pt_amount' => '125.50']],
    'statutory' => ['statutory', ['pan' => 'ABCDE1234F']],
    'qualification' => ['qualifications', ['degree' => 'BSc', 'institution' => 'University', 'year_of_completion' => 2020, 'percentage' => 85]],
    'skill' => ['skills', ['skill_name' => 'Field sales', 'proficiency_level' => 'Advanced']],
    'shift' => ['shifts', ['effective_from' => '2027-01-01', 'effective_to' => null, 'is_current' => true]],
    'week off' => ['week-offs', ['day_of_week' => 0, 'is_active' => true]],
    'bank' => ['banks', ['bank_name' => 'Directory bank', 'bank_account_number' => '00012345', 'bank_account_country' => 'India', 'bank_account_currency' => 'INR', 'bank_account_status' => 'active']],
    'position' => ['positions', ['is_primary' => true]],
]);

it('rejects overlapping shifts and duplicate week offs while deleting complete selections atomically', function () {
    $shift = reportingShift('Bounded day');
    $shift->forceFill(['created_by' => $this->actor->id])->saveQuietly();
    $path = $this->recordPath.'/sections/shifts';
    $input = ['shift_master_id' => $shift->id, 'effective_from' => '2027-01-01', 'effective_to' => null, 'is_current' => true];
    $this->postJson($path, $input + ['revision' => $this->register->revision($this->employee)])->assertOk();
    $this->postJson($path, $input + ['revision' => $this->register->revision($this->employee->fresh())])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
    $this->postJson($path, array_replace($input, ['effective_to' => '2026-12-31', 'revision' => $this->register->revision($this->employee->fresh())]))->assertUnprocessable();
    $weekPath = $this->recordPath.'/sections/week-offs';
    $this->postJson($weekPath, ['day_of_week' => 0, 'is_active' => true, 'revision' => $this->register->revision($this->employee->fresh())])->assertOk();
    $this->postJson($weekPath, ['day_of_week' => 0, 'is_active' => true, 'revision' => $this->register->revision($this->employee->fresh())])->assertUnprocessable();
    $row = $this->employee->weekOffs()->sole();
    $this->postJson($weekPath.'/records/delete', ['ids' => [$row->id, 999999], 'revision' => $this->register->revision($this->employee->fresh())])->assertNotFound();
    expect($row->fresh()->trashed())->toBeFalse();
});

it('keeps one primary position and reselects a primary after detaching', function () {
    $role = JobRole::query()->create(['name' => 'Primary role', 'code' => 'PRIMARYROLE', 'level' => 1]);
    $first = Position::query()->create(['name' => 'First directory position', 'code' => 'FIRSTDIR', 'job_role_id' => $role->id]);
    $second = Position::query()->create(['name' => 'Second directory position', 'code' => 'SECONDDIR', 'job_role_id' => $role->id]);
    foreach ([$first, $second] as $position) {
        $this->register->changeSection($this->actor, $this->employee->id, 'positions', null, ['position_id' => $position->id, 'is_primary' => true], $this->register->revision($this->employee->fresh()));
    }
    expect($this->employee->positions()->wherePivot('is_primary', true)->sole()->id)->toBe($second->id);
    $this->register->changeSection($this->actor, $this->employee->id, 'positions', $second->id, null, $this->register->revision($this->employee->fresh()));
    expect($this->employee->positions()->wherePivot('is_primary', true)->sole()->id)->toBe($first->id);
});

it('uses HR change approval without applying proposed values or provisioning a login early', function () {
    $manager = reportingUser('Directory HR approver');
    $role = JobRole::query()->create(['name' => 'Directory HR approver', 'code' => 'DIRHR', 'level' => 2]);
    $position = Position::query()->create(['name' => 'Directory HR review', 'code' => 'DIRHRPOS', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [HrChangeRequest::class]]);
    $flow = ApprovalFlow::query()->create(['module' => 'HrChangeRequest', 'active' => true]);
    ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    $revision = $this->register->revision($this->employee);
    $this->patchJson($this->recordPath, array_replace($this->input, ['first_name' => 'Proposed', 'revision' => $revision]))->assertUnprocessable();
    $result = $this->postJson($this->recordPath.'/hr-changes/employee', ['values' => ['first_name' => 'Approved change'], 'reason' => 'Correct legal name', 'revision' => $revision])->assertCreated();
    expect($this->employee->fresh()->first_name)->toBe('Register');
    $change = HrChangeRequest::query()->findOrFail($result->json('data.id'));
    app(ApprovalService::class)->approve($change->approval, $manager->id);
    expect($this->employee->fresh()->first_name)->toBe('Approved change')->and(User::query()->where('email', $this->employee->email)->exists())->toBeFalse();
});

it('submits employee approval and locks approved records until an audited override', function () {
    $manager = reportingUser('Directory employee approver');
    $role = JobRole::query()->create(['name' => 'Directory employee approver', 'code' => 'DIREMP', 'level' => 2]);
    $position = Position::query()->create(['name' => 'Directory employee review', 'code' => 'DIREMPPOS', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [Employee::class]]);
    $flow = ApprovalFlow::query()->create(['module' => 'Employee', 'active' => true]);
    ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    $this->postJson($this->recordPath.'/actions/submit', ['revision' => $this->register->revision($this->employee)])->assertOk();
    $this->employee->refresh();
    $this->patchJson($this->recordPath, $this->input + ['revision' => $this->register->revision($this->employee)])->assertForbidden();
    app(ApprovalService::class)->approve($this->employee->approval, $manager->id);
    $this->getJson($this->recordPath)->assertJsonPath('data.can_edit', false);
    $this->actor->givePermissionTo('OverrideApproval:Employee');
    $this->patchJson($this->recordPath, array_replace($this->input, ['phone_number' => '12345', 'revision' => $this->register->revision($this->employee->fresh())]))->assertOk();
    $this->assertDatabaseCount('approval_overrides', 1);
    $this->assertDatabaseHas('approval_overrides', ['model_type' => Employee::class, 'model_id' => $this->employee->id, 'user_id' => $this->actor->id]);
});

it('reads profile images privately and shares Admin creation edits and shift forms with mobile', function () {
    Storage::fake();
    $this->patchJson($this->recordPath, $this->input + ['revision' => $this->register->revision($this->employee), 'image_base64' => base64_encode(UploadedFile::fake()->image('profile.jpg', 20, 20)->get())])->assertOk();
    $this->get($this->recordPath.'/image')->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson($this->recordPath)->assertJsonMissingPath('data.profile_picture');
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $input = array_replace($this->input, ['employee_id' => 'ADMIN-DIR', 'email' => 'admin-directory@example.test', 'mobile_number' => '9111166666']);
    Livewire::test(CreateEmployee::class)->fillForm($input)->call('create')->assertHasNoFormErrors();
    $record = Employee::query()->where('employee_id', 'ADMIN-DIR')->sole();
    Livewire::test(EditEmployee::class, ['record' => $record->id])->fillForm(['phone_number' => '12345'])->call('save')->assertHasNoFormErrors();
    Livewire::test(ListEmployees::class)->assertCanSeeTableRecords([$record])->assertCanNotSeeTableRecords([$this->outside->employee]);
    Livewire::test(ShiftAssignmentsRelationManager::class, ['ownerRecord' => $record, 'pageClass' => EditEmployee::class])->assertSuccessful();
});

it('guards employee deletion atomically and restores the same employee identity', function () {
    $this->postJson($this->path.'/records/delete', ['ids' => [$this->employee->id, $this->actor->employee->id]])->assertConflict();
    expect($this->employee->fresh()->trashed())->toBeFalse();
    $id = $this->employee->id;
    $this->postJson($this->recordPath.'/actions/delete', ['revision' => $this->register->revision($this->employee)])->assertOk();
    $deleted = $this->getJson($this->recordPath)->assertOk()->assertJsonPath('data.trashed', true)->json('data');
    $this->postJson($this->recordPath.'/actions/restore', ['revision' => $deleted['revision']])->assertOk();
    expect($this->employee->fresh()->trashed())->toBeFalse()->and($this->employee->id)->toBe($id)->and($this->employee->employee_id)->toBe('HR-ONE');
});

it('validates division membership and reporting cycles using employee database IDs', function () {
    $type = TypeMaster::query()->create(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
    $division = OrganizationalUnit::query()->create(['name' => 'Directory division', 'code' => 'DIRDIV', 'type_master_id' => $type->id]);
    $otherDivision = OrganizationalUnit::query()->create(['name' => 'Other directory division', 'code' => 'OTHERDIRDIV', 'type_master_id' => $type->id]);
    $unit = OrganizationalUnit::query()->create(['name' => 'Other unit', 'code' => 'OTHERUNIT', 'parent_id' => $otherDivision->id, 'type_master_id' => TypeMaster::query()->create(['name' => 'Branch', 'typeable_type' => OrganizationalUnit::class])->id]);
    $path = $this->recordPath.'/sections/employment';
    $this->postJson($path, ['division_ou_id' => $division->id, 'org_unit_ids' => [$unit->id], 'revision' => $this->register->revision($this->employee)])->assertUnprocessable()->assertJsonValidationErrors('org_unit_ids');
    $this->postJson($path, ['division_ou_id' => $division->id, 'org_unit_ids' => [$division->id], 'hire_date' => '2026-11-01', 'employment_type' => 'Contract', 'revision' => $this->register->revision($this->employee)])->assertOk();
    $this->getJson($this->path.'?employment_type=Contract&hire_from=2026-11-01&hire_to=2026-11-01')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->employee->id);
    $this->patchJson($this->recordPath, $this->input + ['explicit_reporting_manager_id' => $this->actor->employee->id, 'revision' => $this->register->revision($this->employee->fresh())])->assertOk();
    $actorEmployee = $this->actor->employee->fresh();
    $input = $actorEmployee->only(['employee_id', 'first_name', 'last_name', 'email', 'mobile_number', 'login_id', 'is_active']) + ['provision_login' => false, 'explicit_reporting_manager_id' => $this->employee->id, 'revision' => $this->register->revision($actorEmployee)];
    $this->patchJson($this->path.'/'.$actorEmployee->id, $input)->assertUnprocessable()->assertJsonValidationErrors('explicit_reporting_manager_id');
    expect($actorEmployee->fresh()->explicit_reporting_manager_id)->toBeNull();
    $this->getJson($this->recordPath)->assertJsonPath('data.reporting_manager', $actorEmployee->full_name);
});

it('creates grades only with the master permission and validates filters and sorting', function () {
    $this->postJson($this->path.'/options/grades', ['grade_name' => 'Directory grade'])->assertForbidden();
    $this->actor->givePermissionTo(Permission::findOrCreate('Create:EmpGrade', 'web'));
    $this->postJson($this->path.'/options/grades', ['grade_name' => 'Directory grade'])->assertCreated()->assertJsonPath('data.label', 'Directory grade');
    $this->getJson($this->path.'/options/grades?search=Directory')->assertJsonCount(1, 'data');
    $input = array_replace($this->input, ['employee_id' => 'HR-Z', 'first_name' => 'Zed', 'email' => 'zed@example.test', 'mobile_number' => '9111199999']);
    $record = $this->register->save($this->actor, null, $input);
    $this->getJson($this->path.'?search=HR-&sort=first_name&direction=desc')->assertJsonPath('data.0.id', $record->id);
    foreach (['sort=login_id', 'direction=sideways', 'page=0', 'hire_from=2026-11-02&hire_to=2026-11-01', 'employment_status=unknown'] as $query) {
        $this->getJson($this->path.'?'.$query)->assertUnprocessable();
    }
});

it('retains statutory uniqueness and bank defaults without allowing nullable required bank fields', function () {
    $this->postJson($this->recordPath.'/sections/banks', ['bank_name' => 'Bank', 'bank_account_number' => '000100', 'bank_account_status' => 'active', 'bank_account_country' => null, 'bank_account_currency' => null, 'revision' => $this->register->revision($this->employee)])->assertUnprocessable()->assertJsonValidationErrors(['bank_account_country', 'bank_account_currency']);
    $fields = $this->getJson($this->recordPath.'/sections/banks')->assertOk()->json('fields');
    expect(collect($fields)->firstWhere('key', 'bank_account_currency')['default'])->toBe('INR');
    $this->postJson($this->recordPath.'/sections/statutory', ['pan' => 'ABCDE1234F', 'revision' => $this->register->revision($this->employee)])->assertOk();
    $other = $this->register->save($this->actor, null, array_replace($this->input, ['employee_id' => 'HR-STAT', 'email' => 'statutory@example.test', 'mobile_number' => '9111188888']));
    $this->postJson($this->path.'/'.$other->id.'/sections/statutory', ['pan' => 'ABCDE1234F', 'revision' => $this->register->revision($other)])->assertUnprocessable()->assertJsonValidationErrors('pan');
});

it('keeps inaccessible existing position assignments read-only through the employee API', function () {
    Sanctum::actingAs($this->outside);
    $role = JobRole::query()->create(['name' => 'Private directory role', 'code' => 'PRIVATEDIR', 'level' => 1]);
    $position = Position::query()->create(['name' => 'Private directory position', 'code' => 'PRIVATEDIRPOS', 'job_role_id' => $role->id]);
    $this->employee->positions()->attach($position->id, ['is_primary' => true]);
    Sanctum::actingAs($this->actor);
    $this->getJson($this->recordPath.'/sections/positions')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson($this->recordPath.'/sections/positions/'.$position->id, ['revision' => $this->register->revision($this->employee->fresh())])->assertNotFound();
    expect($this->employee->positions()->whereKey($position->id)->exists())->toBeTrue();
});
