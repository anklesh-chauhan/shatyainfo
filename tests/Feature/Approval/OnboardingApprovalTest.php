<?php

use App\Filament\Pages\Onboarding;
use App\Filament\Resources\ApprovalSettings\Pages\EditApprovalSetting;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\LeaveApplication;
use App\Models\Organization;
use App\Models\SalesDcr;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\Visit;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\Attendance\LeaveWorkflowService;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\EmployeeHierarchyImport;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(reportingUser('Setup Owner'));
    $this->organization = Organization::create(['name' => 'Approval Company', 'metadata' => ['company_setup' => ['organization_saved' => true], 'optional_modules' => ['crm' => ['enabled' => true], 'hr' => ['enabled' => true]]]]);
    app()->instance(CompanyOnboardingService::class, new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    });
    $this->setup = app(OnboardingApprovalService::class);
});

it('stores Yes as a draft and creates one shared reporting manager flow when applied', function () {
    $review = $this->setup->saveDraft(['required' => 'yes']);
    expect(array_keys($review['modules']))->toContain('SalesDcr', 'SalesOrder', 'LeaveApplication')
        ->not->toContain('InventoryTransfer', 'SalesTourPlan');
    $this->assertDatabaseCount('approval_flows', 0);

    app(CompanyOnboardingService::class)->finish();
    $flow = ApprovalFlow::sole();
    expect(ApprovalSetting::moduleRequiresApproval('SalesOrder'))->toBeTrue()
        ->and(ApprovalSetting::moduleRequiresApproval('LeaveApplication'))->toBeTrue()
        ->and($flow->active)->toBeTrue()
        ->and($flow->steps()->sole()->routing_type)->toBe('reporting_manager')
        ->and($flow->steps()->sole()->can_skip)->toBeFalse();
    expect($this->organization->fresh()->metadata['company_setup']['completed'])->toBeTrue();
});

it('ignores old manual routing inputs and derives modules from company setup', function () {
    $review = $this->setup->saveDraft(['required' => 'yes', 'modules' => ['InventoryTransfer'], 'common' => ['levels' => 2]]);
    expect(array_keys($review['modules']))->not->toContain('InventoryTransfer');
    expect($this->organization->fresh()->metadata['company_setup']['approvals_draft'])->toBe(['required' => 'yes']);
    $this->setup->apply($this->organization);
    expect(ApprovalFlow::sole()->steps()->count())->toBe(1);
});

it('allows company setup even when an unrelated employee has no reporting manager', function () {
    reportingUser('Unrelated Employee');
    $this->setup->saveDraft(['required' => 'yes']);
    app(CompanyOnboardingService::class)->finish();
    expect(ApprovalFlow::sole()->active)->toBeTrue();
});

it('routes leave to the imported reporting employee after answering Yes', function () {
    $manager = reportingUser('Imported Manager');
    $owner = reportingUser('Imported Requester');
    Gate::before(fn (): bool => true);
    $result = app(EmployeeHierarchyImport::class)->import([[
        'employee_id' => $owner->employee->employee_id,
        'reports_to_employee_code' => $manager->employee->employee_id,
    ]]);
    expect($result->failed)->toBe([]);
    $this->setup->saveDraft(['required' => 'yes']);
    $this->setup->apply($this->organization);
    $this->actingAs($owner);

    $leave = LeaveApplication::create([
        'employee_id' => $owner->employee->id, 'leave_type_id' => reportingLeaveType('Onboarding Leave')->id,
        'from_date' => today(), 'to_date' => today(), 'total_days' => 1, 'approval_status' => 'draft',
    ]);
    app(LeaveWorkflowService::class)->start($leave, []);
    expect($leave->approval()->sole()->steps()->sole()->assigned_user_id)->toBe($manager->id);
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

it('does not duplicate or overwrite configuration on a repeated finish', function () {
    $this->setup->saveDraft(['required' => 'yes']);
    app(CompanyOnboardingService::class)->finish();
    $flow = ApprovalFlow::first();
    $flow->update(['priority' => 42]);
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesOrder']]);
    app(CompanyOnboardingService::class)->finish();
    expect($flow->fresh()->priority)->toBe(42)->and(ApprovalSetting::enabledModules())->toBe(['App\\Models\\SalesOrder']);
    $this->assertDatabaseCount('approval_flows', 1);
});

it('automatically keeps existing admin configuration during onboarding', function () {
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesOrder']]);
    expect($this->setup->saveDraft(['required' => 'no'])['required'])->toBe('existing');
    app(CompanyOnboardingService::class)->finish();
    expect(ApprovalSetting::moduleRequiresApproval('SalesOrder'))->toBeTrue();
});

it('protects configuration added by an admin between review and finish', function () {
    $this->setup->saveDraft(['required' => 'no']);
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesOrder']]);
    app(CompanyOnboardingService::class)->finish();
    expect(ApprovalSetting::moduleRequiresApproval('SalesOrder'))->toBeTrue();
});

it('renders the Yes or No approval choice and manager routing summary in the wizard', function () {
    config(['multitenancy.switch_tenant_tasks' => []]);
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    try {
        Livewire::test(Onboarding::class)
            ->call('skipTerritories')->assertSet('step', 3)
            ->call('next')->assertSet('step', 4)
            ->call('reviewFieldTravel')->assertSet('step', 5)
            ->set('approval.required', 'yes')->assertSee('One approval level routes requests')
            ->call('reviewApprovals')->assertHasNoErrors()->assertSet('step', 6)
            ->assertSee('One approval level routes requests');
        $this->assertDatabaseCount('approval_flows', 0);
    } finally {
        Tenant::forgetCurrent();
    }
});

it('hides approval fields for disabled modules and restores them when enabled', function () {
    $this->setup->saveDraft(['required' => 'no']);
    app(CompanyOnboardingService::class)->finish();
    expect(ApprovalSetting::showsApprovalField(SalesDcr::class, 'approval_status'))->toBeFalse()
        ->and(ApprovalSetting::showsApprovalField(SalesOrder::class, 'approval.approval_status'))->toBeFalse()
        ->and(ApprovalSetting::showsApprovalField(Visit::class, 'sgipDistribution.approval_status'))->toBeFalse();
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesDcr']]);
    expect(ApprovalSetting::showsApprovalField(SalesDcr::class, 'approval_status'))->toBeTrue()
        ->and(ApprovalSetting::showsApprovalField(SalesOrder::class, 'approval.approval_status'))->toBeFalse();
});

it('applies approval visibility to admin table columns filters and form fields', function () {
    $setting = ApprovalSetting::instance();
    $setting->update(['enabled_modules' => []]);
    $table = Table::make(Mockery::mock(HasTable::class))
        ->query(SalesDcr::query());
    $column = TextColumn::make('approval_status')->table($table);
    $filter = SelectFilter::make('approval_status')->table($table);
    $schema = Schema::make()->model(SalesDcr::class)->components([
        Select::make('approval_status'),
        TextEntry::make('approved_at'),
    ]);
    expect($column->isHidden())->toBeTrue()->and($filter->isHidden())->toBeTrue();
    foreach ($schema->getComponents(withHidden: true) as $component) {
        expect($component->isHidden())->toBeTrue();
    }
    $setting->update(['enabled_modules' => ['SalesDcr']]);
    expect($column->isHidden())->toBeFalse()->and($filter->isHidden())->toBeFalse();
    foreach ($schema->getComponents(withHidden: true) as $component) {
        expect($component->isHidden())->toBeFalse();
    }
});

it('switches approvals off and back on from admin settings without removing flows', function () {
    $this->setup->saveDraft(['required' => 'yes']);
    app(CompanyOnboardingService::class)->finish();
    $setting = ApprovalSetting::instance();
    Gate::before(fn (): bool => true);
    config(['multitenancy.switch_tenant_tasks' => []]);
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    try {
        Livewire::test(EditApprovalSetting::class, ['record' => $setting->id])
            ->assertSuccessful()
            ->assertSet('data.approvals_required', true)
            ->fillForm(['approvals_required' => false])->call('save')->assertHasNoFormErrors();
        expect(ApprovalSetting::enabledModules())->toBe([]);
        expect(ApprovalFlow::where('active', true)->count())->toBe(1);
        Livewire::test(EditApprovalSetting::class, ['record' => $setting->id])
            ->assertSuccessful()
            ->assertSet('data.approvals_required', false)
            ->fillForm(['approvals_required' => true, 'enabled_modules' => [SalesOrder::class]])
            ->call('save')->assertHasNoFormErrors();
        expect(ApprovalSetting::moduleRequiresApproval(SalesOrder::class))->toBeTrue();
        expect(ApprovalFlow::where('active', true)->count())->toBe(1);
    } finally {
        Tenant::forgetCurrent();
    }
});
