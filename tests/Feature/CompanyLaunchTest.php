<?php

use App\Filament\Pages\FieldTeamOnboarding;
use App\Filament\Pages\FlexiblePlanning;
use App\Filament\Pages\Onboarding;
use App\Filament\Resources\SalesTourPlans\SalesTourPlanResource;
use App\Filament\Widgets\FieldWorkspace;
use App\Models\FieldPlanningActivation;
use App\Models\FieldUserHq;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Tenant;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\CompanyLaunchService;
use App\Services\CompanyOnboardingService;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use App\Services\Field\FieldTeamOnboardingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = reportingUser('Launch Administrator');
    $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->admin);
    app()->instance(CompanyOnboardingService::class, new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    });
    $this->organization = Organization::query()->create(['name' => 'New Pharma', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->rep = reportingUser('First HQ MR');
    foreach (['Create:Visit', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $this->rep->givePermissionTo($permission);
    }
    FieldUserHq::query()->create(['user_id' => $this->rep->id, 'location_master_id' => reportingLocation('First HQ')->id, 'updated_by' => $this->admin->id]);
    $this->doctor = reportingDoctor($this->rep, 'Assigned Doctor');
    $this->launch = app(CompanyLaunchService::class);
    app(OnboardingApprovalService::class)->saveDraft(['required' => 'no']);
});

it('keeps a chosen workflow inactive until the selected team launches', function (string $mode) {
    $this->launch->saveWorkflow($mode, 0);
    expect(app(FieldPlanningService::class)->active())->toBeNull();
    $revision = $this->launch->proposal($this->organization->fresh());
    $this->launch->launch([$this->rep->employee->id], $revision->id);
    expect(app(FieldPlanningService::class)->active()->mode->value)->toBe($mode)
        ->and($this->launch->forUser($this->rep)['launched'])->toBeTrue()
        ->and($this->launch->forUser($this->admin)['launched'])->toBeFalse();
})->with(['direct_dcr', 'flexible']);

it('validates and stores the company profile timezone', function () {
    $service = app(CompanyOnboardingService::class);
    $data = ['name' => 'Timezone Pharma', 'email' => 'admin@example.test', 'phone' => '9876543210'];
    expect(fn () => $service->saveOrganization([...$data, 'timezone' => 'Not/AZone']))->toThrow(ValidationException::class);
    expect($this->organization->fresh()->name)->toBe('New Pharma');
    $service->saveOrganization([...$data, 'timezone' => 'Asia/Kolkata']);
    expect($this->organization->fresh()->timezone)->toBe('Asia/Kolkata');
});

it('launches Direct DCR without an HQ while still requiring assigned customers', function () {
    $this->launch->saveWorkflow('direct_dcr', 0);
    FieldUserHq::query()->where('user_id', $this->rep->id)->delete();
    $member = $this->launch->readiness([$this->rep->employee->id])['members'][0];
    expect($member['ready'])->toBeTrue()->and($member['hq'])->toBeNull();
    $revision = $this->launch->proposal($this->organization->fresh());
    $this->doctor->update(['owner_id' => $this->admin->id]);
    $member = $this->launch->readiness([$this->rep->employee->id])['members'][0];
    expect($member['issue_details'])->toHaveKey('customers')->not->toHaveKey('hq');
    expect(fn () => $this->launch->launch([$this->rep->employee->id], $revision->id))->toThrow(ValidationException::class);
    $this->doctor->update(['owner_id' => $this->rep->id]);
    $this->launch->launch([$this->rep->employee->id], $revision->id);
    expect($this->launch->forUser($this->rep)['launched'])->toBeTrue()
        ->and($this->launch->forUser($this->rep)['hq_name'])->toBeNull();
});

it('retains mandatory HQ checks for other working methods', function (string $mode) {
    $this->launch->saveWorkflow($mode, 0);
    FieldUserHq::query()->where('user_id', $this->rep->id)->delete();
    $member = $this->launch->readiness([$this->rep->employee->id])['members'][0];
    expect($member['issue_details'])->toHaveKey('hq');
    expect(fn () => app(FieldTeamOnboardingService::class)->save($this->admin, [
        'user_id' => $this->rep->id, 'location_master_id' => null,
        'account_ids' => [$this->doctor->id], 'calls_per_month' => 2,
    ]))->toThrow(ValidationException::class);
})->with(['flexible', 'formal_mtp']);

it('assigns customers through Admin without an HQ for a saved Direct DCR proposal', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    foreach (['ViewAny:AccountMaster', 'Update:AccountMaster'] as $permission) {
        $this->admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->launch->saveWorkflow('direct_dcr', 0);
    FieldUserHq::query()->where('user_id', $this->rep->id)->delete();
    $this->doctor->update(['owner_id' => $this->admin->id]);
    Livewire::test(FieldTeamOnboarding::class)
        ->assertSee('HQ, territories and patches are optional')
        ->fillForm(['user_id' => $this->rep->id, 'location_master_id' => null,
            'account_ids' => [$this->doctor->id], 'calls_per_month' => 2])
        ->call('save')->assertHasNoFormErrors()->assertNotified();
    expect($this->doctor->fresh()->owner_id)->toBe($this->rep->id)
        ->and($this->doctor->callTargets()->sole()->calls_per_month)->toBe(2)
        ->and(FieldUserHq::query()->where('user_id', $this->rep->id)->exists())->toBeFalse();
});

it('preserves an existing HQ when it is left blank during Direct DCR customer assignment', function () {
    $this->admin->givePermissionTo(Permission::findOrCreate('Update:AccountMaster', 'web'));
    $this->launch->saveWorkflow('direct_dcr', 0);
    $hq = FieldUserHq::query()->where('user_id', $this->rep->id)->sole();
    $result = app(FieldTeamOnboardingService::class)->save($this->admin, [
        'user_id' => $this->rep->id, 'account_ids' => [$this->doctor->id], 'calls_per_month' => 2,
    ]);
    expect($result['hq_id'])->toBe($hq->id)
        ->and($hq->fresh()->location_master_id)->toBe($hq->location_master_id);
});

it('resolves a single reporting manager and falls back when the position is ambiguous', function () {
    $manager = reportingUser('First HQ Manager');
    $other = reportingUser('Other HQ Manager');
    $role = JobRole::query()->create(['name' => 'Contact Manager', 'code' => 'CONTACT-MGR', 'level' => 2]);
    $parent = Position::query()->create(['name' => 'HQ Manager', 'code' => 'CONTACT-PARENT', 'job_role_id' => $role->id]);
    $child = Position::query()->create(['name' => 'HQ MR', 'code' => 'CONTACT-CHILD',
        'job_role_id' => $role->id, 'reports_to_position_id' => $parent->id]);
    $manager->employee->positions()->attach($parent->id, ['is_primary' => true]);
    $this->rep->employee->positions()->attach($child->id, ['is_primary' => true]);
    $contact = $this->launch->forUser($this->rep->fresh());
    expect($contact['contact_kind'])->toBe('manager')
        ->and($contact['contact'])->toBe($manager->employee->full_name)
        ->and($contact['contact_email'])->toBe($manager->employee->email);
    $this->organization->update(['contact_person' => 'Company Help', 'contact_person_phone' => '9876543210']);
    $other->employee->positions()->attach($parent->id, ['is_primary' => true]);
    $fallback = $this->launch->forUser($this->rep->fresh());
    expect($fallback['contact_kind'])->toBe('administrator')
        ->and($fallback['contact'])->toBe('Company Help')
        ->and($fallback['contact_phone'])->toBe('9876543210');
    $other->employee->positions()->detach($parent->id);
    $manager->employee->forceFill(['is_active' => false])->saveQuietly();
    expect($this->launch->forUser($this->rep->fresh())['contact_kind'])->toBe('administrator');
});

it('keeps incomplete drafts private without changing company readiness or policy', function () {
    $service = app(CompanyOnboardingService::class);
    $draft = ['step' => 1, 'organization' => ['name' => '', 'email' => 'unfinished'],
        'workflow' => '', 'workflow_version' => 0, 'approval' => []];
    $service->saveDraft($draft);
    expect($service->draft())->toEqual($draft)
        ->and($this->organization->fresh()->name)->toBe('New Pharma')
        ->and(FieldPlanningActivation::query()->count())->toBe(0);
    $this->actingAs($this->rep);
    expect($service->draft())->toBe([]);
    $this->actingAs($this->admin);
    $service->forgetDraft();
    expect($service->draft())->toBe([]);
});

it('resumes incomplete company input and approval drafts without applying them', function () {
    config(['multitenancy.switch_tenant_tasks' => []]);
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99998, 'name' => 'Draft Test', 'master_setup_status' => 'ready']);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    try {
        Livewire::test(Onboarding::class)->call('back')->assertSet('step', 1)
            ->assertSet('organization.timezone', 'Asia/Kolkata')
            ->set('organization.name', '')->set('organization.email', 'unfinished')
            ->set('organization.timezone', 'Europe/London')
            ->call('skip')->assertHasNoErrors()->assertRedirect('/admin');
        Livewire::test(Onboarding::class)->assertSet('step', 1)
            ->assertSet('organization.name', '')->assertSet('organization.email', 'unfinished')
            ->assertSet('organization.timezone', 'Europe/London')
            ->call('saveOrganization')->assertHasErrors(['organization.name', 'organization.email']);
        expect($this->organization->fresh()->name)->toBe('New Pharma');
        $draft = app(CompanyOnboardingService::class)->draft();
        $this->launch->saveWorkflow('direct_dcr', 0);
        app(CompanyOnboardingService::class)->saveDraft([...$draft, 'step' => 6,
            'workflow' => 'direct_dcr', 'workflow_version' => 1,
            'approval' => ['required' => 'yes', 'modules' => [], 'common' => []]]);
        Livewire::test(Onboarding::class)->assertSet('step', 5)->assertSet('approval.required', 'yes');
        expect(FieldPlanningActivation::query()->count())->toBe(0);
    } finally {
        Tenant::forgetCurrent();
    }
});

it('rolls back policy activation and approvals when selected members are not ready', function () {
    $this->launch->saveWorkflow('direct_dcr', 0);
    $this->doctor->delete();
    $revision = $this->launch->proposal($this->organization->fresh());
    expect(fn () => $this->launch->launch([$this->rep->employee->id], $revision->id))->toThrow(ValidationException::class);
    expect(FieldPlanningActivation::query()->count())->toBe(0)
        ->and($this->organization->fresh()->metadata['company_setup']['approvals_applied'] ?? false)->toBeFalse()
        ->and($this->launch->forUser($this->rep)['launched'])->toBeFalse();
});

it('keeps partial launches independent and repeated launches idempotent', function () {
    $this->launch->saveWorkflow('direct_dcr', 0);
    $revision = $this->launch->proposal($this->organization->fresh());
    $other = reportingUser('Unprepared HQ');
    $this->launch->launch([$this->rep->employee->id], $revision->id);
    $first = $this->organization->fresh()->metadata['company_setup']['launched_users'];
    $this->travel(1)->hours();
    $this->launch->launch([$this->rep->employee->id], $revision->id);
    expect(FieldPlanningActivation::query()->count())->toBe(1)
        ->and($this->organization->fresh()->metadata['company_setup']['launched_users'])->toBe($first)
        ->and($this->launch->forUser($other)['launched'])->toBeFalse();
});

it('requires formal planning geography but not for direct reporting', function () {
    $this->launch->saveWorkflow('formal_mtp', 0);
    $review = $this->launch->readiness([$this->rep->employee->id]);
    expect($review['members'][0]['issues'])->toContain('Assign a position with a territory for this workflow.', 'Create a patch in the assigned territory.');
    $this->launch->saveWorkflow('direct_dcr', 1);
    expect($this->launch->readiness([$this->rep->employee->id])['ready_count'])->toBe(1);
});

it('rejects missing HQ and revoked permissions for flexible planning', function () {
    $this->launch->saveWorkflow('flexible', 0);
    $this->rep->revokePermissionTo('Create:Visit');
    FieldUserHq::query()->where('user_id', $this->rep->id)->delete();
    $review = $this->launch->readiness([$this->rep->employee->id]);
    expect($review['ready_count'])->toBe(0)
        ->and($review['members'][0]['issues'])->toContain('Grant visit creation and customer list access.', 'Assign an active HQ.');
});

it('rejects stale workflow review and invalid selected employees', function () {
    $this->launch->saveWorkflow('direct_dcr', 0);
    $revision = $this->launch->proposal($this->organization->fresh());
    $this->launch->saveWorkflow('flexible', 1);
    expect(fn () => $this->launch->launch([$this->rep->employee->id], $revision->id))->toThrow(ValidationException::class);
    expect(fn () => $this->launch->launch([999999], $revision->id))->toThrow(ValidationException::class);
    expect(FieldPlanningActivation::query()->count())->toBe(0);
});

it('does not count owned customers that the MR cannot see', function () {
    $this->launch->saveWorkflow('direct_dcr', 0);
    $this->rep->revokePermissionTo('ViewOwn:AccountMaster');
    $this->doctor->forceFill(['created_by' => $this->admin->id])->saveQuietly();
    expect($this->launch->readiness([$this->rep->employee->id])['members'][0]['issues'])
        ->toContain('Grant visibility of the assigned customers.');
});

it('checks the DCR approver against the MR rather than the administrator', function () {
    $this->launch->saveWorkflow('direct_dcr', 0);
    $role = JobRole::query()->create(['name' => 'Approver', 'code' => 'LAUNCH_APPROVER', 'level' => 2]);
    $position = Position::query()->create(['name' => 'Approver', 'code' => 'LAUNCH_APPROVER', 'job_role_id' => $role->id]);
    $this->rep->employee->positions()->attach($position->id);
    $input = app(OnboardingApprovalService::class)->defaults();
    $input['required'] = 'yes';
    $input['modules'] = ['SalesDcr'];
    $input['common']['l1_role_id'] = $role->id;
    $input['common']['l1_user_id'] = $this->rep->id;
    app(OnboardingApprovalService::class)->saveDraft($input);
    $revision = $this->launch->proposal($this->organization->fresh());
    expect(fn () => $this->launch->launch([$this->rep->employee->id], $revision->id))->toThrow(ValidationException::class);
    expect(FieldPlanningActivation::query()->count())->toBe(0);
});

it('requires login linkage even when an employee record exists', function () {
    $this->launch->saveWorkflow('direct_dcr', 0);
    $employee = $this->rep->employee;
    $employee->forceFill(['login_id' => null])->saveQuietly();
    expect($this->launch->readiness([$employee->id])['members'][0]['issues'])->toContain('Create and link login access.');
});

it('renders workflow selection and resumes the saved choice in Admin', function () {
    config(['multitenancy.switch_tenant_tasks' => []]);
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99998, 'name' => 'Launch Test', 'master_setup_status' => 'ready']);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    try {
        Livewire::test(Onboarding::class)
            ->assertSet('step', 2)
            ->assertSee('How does your field team plan doctor visits?')
            ->assertSee('Report visits directly')
            ->assertSee('Require monthly plans')
            ->set('workflow', 'direct_dcr')->call('saveWorkflow')
            ->assertHasNoErrors()->assertSet('step', 3);
        Livewire::test(Onboarding::class)
            ->assertSet('workflow', 'direct_dcr')->assertSet('step', 3);
        expect(FieldPlanningActivation::query()->count())->toBe(0);
    } finally {
        Tenant::forgetCurrent();
    }
});

it('does not accept a future policy as ready for launch today', function () {
    $settings = app(FieldPlanningSettingsService::class);
    $revision = $settings->saveProposal($this->admin, [
        ...$settings->initialProposal(), 'reason' => 'Start next week',
        'proposed_effective_on' => today()->addWeek()->toDateString(),
    ], 0);
    $metadata = $this->organization->metadata;
    $metadata['company_setup']['planning_revision_id'] = $revision->id;
    $this->organization->update(['metadata' => $metadata]);
    expect($this->launch->readiness([$this->rep->employee->id])['ready_count'])->toBe(0);
    expect(fn () => $this->launch->launch([$this->rep->employee->id], $revision->id))->toThrow(ValidationException::class);
});

it('renders personal field actions without showing another users approval queue', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->launch->saveWorkflow('direct_dcr', 0);
    $revision = $this->launch->proposal($this->organization->fresh());
    $this->launch->launch([$this->rep->employee->id], $revision->id);
    $this->actingAs($this->rep);
    Livewire::test(FieldWorkspace::class)
        ->assertSuccessful()->assertSee('Start visit')->assertDontSee('Team management');
});

it('switches between personal and team actions while navigation follows the active workflow', function (string $mode) {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->launch->saveWorkflow($mode, 0);
    $revision = $this->launch->proposal($this->organization->fresh());
    $this->launch->launch([$this->rep->employee->id], $revision->id);
    $this->actingAs($this->rep);
    expect(SalesTourPlanResource::shouldRegisterNavigation())->toBeFalse()
        ->and(FlexiblePlanning::shouldRegisterNavigation())->toBe($mode === 'flexible');
    $this->rep->givePermissionTo(Permission::findOrCreate(FieldTeamOnboardingService::PERMISSION, 'web'));
    expect(SalesTourPlanResource::shouldRegisterNavigation())->toBeTrue();
    Livewire::test(FieldWorkspace::class)
        ->assertSee('Personal work')->assertSee('Team management')->assertSee('Start visit')
        ->call('switchWorkspace', 'team')->assertSee('Team coverage')->assertDontSee('Start visit')
        ->call('switchWorkspace', 'personal')->assertSee('Start visit')->assertDontSee('Team coverage');
})->with(['direct_dcr', 'flexible']);

it('records launch batches once and keeps the original member names and HQs', function () {
    $this->launch->saveWorkflow('direct_dcr', 0);
    $revision = $this->launch->proposal($this->organization->fresh());
    $this->launch->launch([$this->rep->employee->id], $revision->id);
    $history = $this->launch->history();
    expect($history)->toHaveCount(1)
        ->and($history[0]['launched_by'])->toBe($this->admin->id)
        ->and($history[0]['policy_revision_id'])->toBe($revision->id)
        ->and($history[0]['members'][0]['user_id'])->toBe($this->rep->id)
        ->and($history[0]['members'][0]['hq'])->toBe('First HQ');
    $this->launch->launch([$this->rep->employee->id], $revision->id);
    $this->rep->employee->forceFill(['first_name' => 'Renamed'])->saveQuietly();
    expect($this->launch->history())->toBe($history);

    $this->admin->givePermissionTo(['Create:Visit', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster']);
    FieldUserHq::query()->create(['user_id' => $this->admin->id, 'location_master_id' => reportingLocation('Second HQ')->id, 'updated_by' => $this->admin->id]);
    reportingDoctor($this->admin, 'Second HQ doctor');
    $this->launch->launch([$this->rep->employee->id, $this->admin->employee->id], $revision->id);
    expect($this->launch->history())->toHaveCount(2)
        ->and($this->launch->history()[0]['members'])->toHaveCount(1)
        ->and($this->launch->history()[0]['members'][0]['user_id'])->toBe($this->admin->id);
});

it('does not record failed launches and requires setup access to read history', function () {
    $this->launch->saveWorkflow('direct_dcr', 0);
    $revision = $this->launch->proposal($this->organization->fresh());
    $this->doctor->delete();
    expect(fn () => $this->launch->launch([$this->rep->employee->id], $revision->id))->toThrow(ValidationException::class);
    expect($this->launch->history())->toBe([]);
    app()->instance(CompanyOnboardingService::class, new CompanyOnboardingService);
    $this->actingAs($this->rep);
    expect(fn () => $this->launch->history())->toThrow(HttpException::class);
});

it('provides stable issue keys and preselects the affected representative', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    FieldUserHq::query()->where('user_id', $this->rep->id)->delete();
    $member = $this->launch->readiness([$this->rep->employee->id])['members'][0];
    expect($member['issue_details']['hq'])->toBe('Assign an active HQ.');
    $action = (new Onboarding)->readinessAction('hq', $member);
    expect($action['url'])->toContain('representative='.$this->rep->id);
    Livewire::withQueryParams(['representative' => $this->rep->id])
        ->test(FieldTeamOnboarding::class)
        ->assertFormSet(['user_id' => $this->rep->id, 'account_ids' => []]);
});

it('ignores malformed representative query parameters without changing assignments', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::withQueryParams(['representative' => ['unexpected']])
        ->test(FieldTeamOnboarding::class)
        ->assertSuccessful()->assertFormSet(['user_id' => null, 'account_ids' => []]);
    expect(FieldUserHq::query()->where('user_id', $this->rep->id)->count())->toBe(1);
});
