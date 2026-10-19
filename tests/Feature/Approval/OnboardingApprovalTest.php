<?php

use App\Filament\Pages\Onboarding;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Tenant;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\CompanyOnboardingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(reportingUser('Setup Owner'));
    $this->organization = Organization::create(['name' => 'Approval Company', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    app()->instance(CompanyOnboardingService::class, new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    });
    $this->setup = app(OnboardingApprovalService::class);
});

function onboardingRole(string $code): JobRole
{
    $user = reportingUser('Approver '.$code);
    $role = JobRole::create(['name' => 'Role '.$code, 'code' => $code, 'level' => 2]);
    $position = Position::create(['name' => 'Position '.$code, 'code' => $code, 'job_role_id' => $role->id]);
    $user->employee->positions()->attach($position->id);

    return $role;
}

function onboardingInput(JobRole $role): array
{
    return array_replace(app(OnboardingApprovalService::class)->defaults(), ['required' => 'yes', 'modules' => ['SalesOrder', 'LeaveApplication'], 'common' => ['levels' => 1, 'territory_id' => '', 'l1_role_id' => $role->id, 'l1_scope' => 'all', 'l2_role_id' => '', 'l2_scope' => 'all']]);
}

it('stores approval choices as a draft and creates visible L1 flows on finish', function () {
    $role = onboardingRole('L1');
    $review = $this->setup->saveDraft(onboardingInput($role));
    expect($review['flows'])->toHaveCount(2);
    $this->assertDatabaseCount('approval_flows', 0);
    app(CompanyOnboardingService::class)->finish();
    expect(ApprovalSetting::moduleRequiresApproval('SalesOrder'))->toBeTrue();
    expect(ApprovalSetting::moduleRequiresApproval('LeaveApplication'))->toBeTrue();
    expect(ApprovalFlow::where('active', true)->count())->toBe(2);
    expect(ApprovalFlow::first()->steps()->first()->can_skip)->toBeFalse();
    expect($this->organization->fresh()->metadata['company_setup']['completed'])->toBeTrue();
});

it('creates two ordered levels and honors per-module overrides', function () {
    $input = onboardingInput(onboardingRole('L1'));
    $input['common']['levels'] = 2;
    $input['common']['l2_role_id'] = onboardingRole('L2')->id;
    $input['overrides']['LeaveApplication'] = [...$input['common'], 'custom' => true, 'levels' => 1];
    $this->setup->saveDraft($input);
    $this->setup->apply($this->organization);
    expect(ApprovalFlow::where('module', 'SalesOrder')->first()->steps()->pluck('step_order')->all())->toBe([1, 2]);
    expect(ApprovalFlow::where('module', 'LeaveApplication')->first()->steps()->count())->toBe(1);
});

it('records an explicit no-approval choice without creating flows', function () {
    $this->setup->saveDraft(['required' => 'no']);
    app(CompanyOnboardingService::class)->finish();
    expect(ApprovalSetting::enabledModules())->toBe([]);
    $this->assertDatabaseCount('approval_flows', 0);
    expect($this->organization->fresh()->metadata['company_setup']['approvals_applied'])->toBeTrue();
});

it('requires approval preferences before completing onboarding', function () {
    expect(fn () => app(CompanyOnboardingService::class)->finish())->toThrow(ValidationException::class);
    expect($this->organization->fresh()->metadata['company_setup']['completed'] ?? false)->toBeFalse();
});

it('rejects unsupported modules missing approvers and duplicate L1 L2 people', function (string $case) {
    $role = onboardingRole('L1');
    $input = onboardingInput($role);
    if ($case === 'unsupported') {
        $input['modules'] = ['Employee'];
    }
    if ($case === 'missing') {
        $input['common']['l1_role_id'] = JobRole::create(['name' => 'Empty', 'code' => 'EMPTY', 'level' => 2])->id;
    }
    if ($case === 'same') {
        $input['common']['levels'] = 2;
        $input['common']['l2_role_id'] = $role->id;
    }
    if ($case === 'territory') {
        $input['common']['l1_scope'] = 'self';
    }
    expect(fn () => $this->setup->saveDraft($input))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('approval_flows', 0);
})->with(['unsupported', 'missing', 'same', 'territory']);

it('revalidates approvers at finish and leaves no partial configuration', function () {
    $role = onboardingRole('L1');
    $this->setup->saveDraft(onboardingInput($role));
    Employee::whereHas('positions', fn ($query) => $query->where('job_role_id', $role->id))->update(['is_active' => false]);
    expect(fn () => app(CompanyOnboardingService::class)->finish())->toThrow(ValidationException::class);
    $this->assertDatabaseCount('approval_flows', 0);
    expect(ApprovalSetting::enabledModules())->toBe([]);
});

it('does not duplicate or overwrite configuration on a repeated finish', function () {
    $this->setup->saveDraft(onboardingInput(onboardingRole('L1')));
    app(CompanyOnboardingService::class)->finish();
    $flow = ApprovalFlow::first();
    $flow->update(['priority' => 42]);
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesOrder']]);
    app(CompanyOnboardingService::class)->finish();
    expect($flow->fresh()->priority)->toBe(42)->and(ApprovalSetting::enabledModules())->toBe(['App\\Models\\SalesOrder']);
    $this->assertDatabaseCount('approval_flows', 2);
});

it('requires an explicit keep-existing choice when admin configuration exists', function () {
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesOrder']]);
    expect(fn () => $this->setup->saveDraft(['required' => 'no']))->toThrow(ValidationException::class);
    $this->setup->saveDraft(['required' => 'existing']);
    app(CompanyOnboardingService::class)->finish();
    expect(ApprovalSetting::moduleRequiresApproval('SalesOrder'))->toBeTrue();
});

it('protects configuration added by an admin between review and finish', function () {
    $this->setup->saveDraft(['required' => 'no']);
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesOrder']]);
    expect(fn () => app(CompanyOnboardingService::class)->finish())->toThrow(ValidationException::class);
    expect(ApprovalSetting::moduleRequiresApproval('SalesOrder'))->toBeTrue();
});

it('renders approval selection overrides and review in the onboarding wizard', function () {
    config(['multitenancy.switch_tenant_tasks' => []]);
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    try {
        $input = onboardingInput(onboardingRole('SCREEN'));
        Livewire::test(Onboarding::class)
            ->call('next')->assertSet('step', 3)
            ->set('approval', $input)->assertSee('Common approval setup')
            ->set('approval.overrides.LeaveApplication.custom', true)
            ->call('reviewApprovals')->assertHasNoErrors()->assertSet('step', 4)
            ->assertSee('These flows will become active when you finish setup.');
        $this->assertDatabaseCount('approval_flows', 0);
    } finally {
        Tenant::forgetCurrent();
    }
});
