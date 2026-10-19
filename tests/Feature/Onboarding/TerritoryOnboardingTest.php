<?php

use App\Filament\Pages\Onboarding;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\Territory;
use App\Models\User;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\EmployeeHierarchyImport;
use App\Services\TerritoryOnboardingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(reportingUser('Setup Owner'));
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->organization = Organization::create(['name' => 'Territory Company', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    app()->instance(CompanyOnboardingService::class, new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    });
    $this->service = app(TerritoryOnboardingService::class);
});

function territoryRows(): array
{
    return [
        ['code' => 'MUMBAI', 'name' => 'Mumbai', 'parent_code' => 'WEST'],
        ['code' => 'WEST', 'name' => 'West', 'parent_code' => ''],
    ];
}

function territoryEmployeeRow(): array
{
    return ['employee_id' => 'T001', 'first_name' => 'Territory', 'last_name' => 'Employee', 'mobile_number' => '9988776655', 'job_role' => 'Territory Manager', 'position' => 'West Manager', 'position_code' => 'WEST-MGR', 'territory_codes' => 'WEST;MUMBAI', 'is_primary_position' => 'Yes'];
}

it('previews and imports children before parents and safely repeats the import', function () {
    $preview = $this->service->preview(territoryRows());
    expect($preview[0]['action'])->toBe('Create');
    $this->assertDatabaseCount('territories', 0);
    expect($this->service->import($preview))->toBe(2);
    expect(Territory::where('code', 'MUMBAI')->sole()->parent_territory_id)->toBe(Territory::where('code', 'WEST')->sole()->id);
    expect($this->service->import(territoryRows()))->toBe(0);
    $this->assertDatabaseCount('territories', 2);
    expect($this->organization->fresh()->metadata['company_setup']['territories_reviewed'])->toBeTrue();
});

it('rejects invalid territory hierarchies without partial writes', function (string $case) {
    $rows = territoryRows();
    if ($case === 'parent') {
        $rows[0]['parent_code'] = 'MISSING';
    } elseif ($case === 'cycle') {
        $rows[1]['parent_code'] = 'MUMBAI';
    } elseif ($case === 'duplicate') {
        $rows[1]['code'] = 'mumbai';
    } elseif ($case === 'self') {
        $rows[0]['parent_code'] = 'MUMBAI';
    } else {
        $rows[0]['status'] = 'unknown';
    }
    expect(fn () => $this->service->import($rows))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('territories', 0);
})->with(['parent', 'cycle', 'duplicate', 'self', 'status']);

it('revalidates the preview and preserves admin edits', function () {
    $preview = $this->service->preview(territoryRows());
    Territory::create(['name' => 'Existing West', 'code' => 'WEST', 'status' => 'active']);
    expect(fn () => $this->service->import($preview))->toThrow(ValidationException::class);
    expect(Territory::where('code', 'WEST')->sole()->name)->toBe('Existing West');
    $this->assertDatabaseCount('territories', 1);
});

it('resolves uploaded territory codes through employee positions and preserves repeat assignments', function () {
    $this->service->import(territoryRows());
    $importer = app(EmployeeHierarchyImport::class);
    expect($importer->import([2 => territoryEmployeeRow()])->failed)->toBe([]);
    expect(Position::where('code', 'WEST-MGR')->sole()->territories()->count())->toBe(2);
    expect(Employee::where('employee_id', 'T001')->sole()->positions()->count())->toBe(1);
    expect($importer->import([2 => territoryEmployeeRow()])->failed)->toBe([]);
    expect(Position::where('code', 'WEST-MGR')->sole()->territories()->count())->toBe(2);
});

it('rolls back employee imports with missing territory codes', function () {
    $result = app(EmployeeHierarchyImport::class)->import([2 => territoryEmployeeRow()]);
    expect($result->failed)->not->toBeEmpty();
    expect(Employee::where('employee_id', 'T001')->exists())->toBeFalse();
    expect(Position::where('code', 'WEST-MGR')->exists())->toBeFalse();
});

it('rejects conflicting position territories without replacing existing assignments', function () {
    $this->service->import(territoryRows());
    $importer = app(EmployeeHierarchyImport::class);
    expect($importer->import([2 => territoryEmployeeRow()])->failed)->toBe([]);
    expect($importer->import([2 => [...territoryEmployeeRow(), 'territory_codes' => 'WEST']])->failed)->not->toBeEmpty();
    expect(Position::where('code', 'WEST-MGR')->sole()->territories()->count())->toBe(2);
});

it('shows territory upload before employees and resumes after skipping', function () {
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    try {
        auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(Onboarding::class)
            ->assertSet('step', 2)->assertSee('Set up your territories')
            ->call('next')->assertHasErrors(['territories'])
            ->call('skipTerritories')->assertSet('step', 3);
        Livewire::test(Onboarding::class)->assertSet('step', 3)
            ->call('next')->assertSet('step', 4);
        Livewire::test(Onboarding::class)->assertSet('step', 4);
    } finally {
        Tenant::forgetCurrent();
    }
});

it('previews a CSV upload and imports only after confirmation', function () {
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    try {
        auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(Onboarding::class)
            ->set('territoryFile', UploadedFile::fake()->createWithContent('territories.csv', "code,name,parent_code\nMUMBAI,Mumbai,WEST\nWEST,West,\n"))
            ->call('previewTerritories')->assertHasNoErrors()->assertSee('Review before importing');
        $this->assertDatabaseCount('territories', 0);
        $page->call('importTerritories')->assertHasNoErrors()->call('next')->assertSet('step', 3);
        $this->assertDatabaseCount('territories', 2);
    } finally {
        Tenant::forgetCurrent();
    }
});

it('uses imported position territories to resolve a scoped approval approver', function () {
    $this->service->import(territoryRows());
    expect(app(EmployeeHierarchyImport::class)->import([2 => territoryEmployeeRow()])->failed)->toBe([]);
    $approver = User::factory()->create();
    Employee::where('employee_id', 'T001')->sole()->update(['login_id' => $approver->id, 'is_active' => true]);
    $setup = app(OnboardingApprovalService::class);
    $input = $setup->defaults();
    $input['required'] = 'yes';
    $input['modules'] = ['SalesOrder'];
    $input['common']['territory_id'] = Territory::where('code', 'WEST')->sole()->id;
    $input['common']['l1_role_id'] = Position::where('code', 'WEST-MGR')->sole()->job_role_id;
    $input['common']['l1_scope'] = 'self';
    expect($setup->review($input)['flows'][0]['steps'][0]['approver'])->toBe($approver->name);
});
