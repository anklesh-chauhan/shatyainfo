<?php

use App\Filament\Pages\FieldTravelImport;
use App\Filament\Pages\Onboarding;
use App\Models\City;
use App\Models\CityPinCode;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\State;
use App\Models\Tenant;
use App\Models\Territory;
use App\Models\TypeMaster;
use App\Models\User;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\EmployeeHierarchyImport;
use App\Services\DataImport\TerritoryImportTemplate;
use App\Services\TerritoryOnboardingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
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
    $type = TypeMaster::firstOrCreate(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
    OrganizationalUnit::create(['code' => 'DIV-WEST', 'name' => 'West Division', 'type_master_id' => $type->id]);
    OrganizationalUnit::create(['code' => 'DIV-SOUTH', 'name' => 'South Division', 'type_master_id' => $type->id]);
    $country = Country::create(['name' => 'India']);
    $state = State::create(['name' => 'Maharashtra', 'country_id' => $country->id]);
    $city = City::create(['name' => 'Mumbai', 'state_id' => $state->id, 'country_id' => $country->id]);
    CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Andheri', 'pin_code' => 400058]);
    CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Bandra', 'pin_code' => 400050]);
    $this->service = app(TerritoryOnboardingService::class);
});

function territoryRows(): array
{
    return [
        ['code' => 'MUMBAI', 'name' => 'Mumbai', 'parent_code' => 'WEST', 'division_codes' => 'DIV-WEST', 'cities_and_areas' => 'Mumbai|Andheri|400058'],
        ['code' => 'WEST', 'name' => 'West', 'parent_code' => '', 'division_codes' => 'DIV-WEST', 'cities_and_areas' => 'Mumbai|Andheri|400058'],
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
        Livewire::test(Onboarding::class)->assertSet('step', 4)
            ->assertSee('Set up field routes')
            ->assertSee('Skip and complete later')
            ->call('reviewFieldTravel')->assertSet('step', 5)
            ->assertSee('Does your team need approvals?');
        Livewire::test(Onboarding::class)->assertSet('step', 5);
        Livewire::withQueryParams(['section' => 'field-travel'])->test(Onboarding::class)
            ->assertSet('step', 4)->assertSee('Import account masters')->assertDontSee('Customers and addresses');
        foreach (['Patch', 'StandardFareChart'] as $model) {
            auth()->user()->givePermissionTo(Permission::findOrCreate('Create:'.$model, 'web'));
        }
        Livewire::withQueryParams(['onboarding' => 1])->test(FieldTravelImport::class)
            ->assertSuccessful()->assertSet('fromOnboarding', true)
            ->assertSee('Return to company setup')
            ->assertDontSee('Import historical travel segments instead of patches and fares');
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
            ->set('territoryFile', UploadedFile::fake()->createWithContent('territories.csv', "code,name,parent_code,division_codes,cities_and_areas\nMUMBAI,Mumbai,WEST,DIV-WEST,Mumbai|Andheri|400058\nWEST,West,,DIV-WEST,Mumbai|Andheri|400058\n"))
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

it('provides a blank territory workbook with instructions and separate examples', function () {
    $path = sys_get_temp_dir().'/territory-template-'.bin2hex(random_bytes(8)).'.xlsx';
    try {
        app(TerritoryImportTemplate::class)->write($path);
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets[$sheet->getName()] = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $sheets[$sheet->getName()][] = $row->toArray();
                }
            }
        } finally {
            $reader->close();
        }
        expect(array_keys($sheets))->toBe(['Territories', 'Instructions', 'Examples']);
        expect($sheets['Territories'][0])->toBe(TerritoryOnboardingService::COLUMNS);
        expect(count($sheets['Instructions']))->toBeGreaterThan(10);
        expect($sheets['Examples'][1][0])->toBe('MUMBAI');
        expect($this->service->readFile($path, true))->toBe([]);
        $this->assertDatabaseCount('territories', 0);
    } finally {
        unlink($path);
    }
});

it('reads only the Territories worksheet even when instructions come first', function () {
    $path = sys_get_temp_dir().'/territory-upload-'.bin2hex(random_bytes(8)).'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Instructions');
    $writer->addRow(Row::fromValues(['Do not import this sheet']));
    $writer->addNewSheetAndMakeItCurrent()->setName('Territories');
    $writer->addRow(Row::fromValues(TerritoryOnboardingService::COLUMNS));
    $writer->addRow(Row::fromValues(['WEST', 'West', '', 'active', '', 'DIV-WEST', 'Mumbai|Andheri|400058', '']));
    $writer->addNewSheetAndMakeItCurrent()->setName('Examples');
    $writer->addRow(Row::fromValues(['EXAMPLE', 'Example', '', 'active', '']));
    $writer->close();
    try {
        $rows = $this->service->readFile($path, true);
        expect($rows)->toHaveCount(1)->and($rows[0]['code'])->toBe('WEST');
        expect($this->service->import($rows))->toBe(1);
        $this->assertDatabaseCount('territories', 1);
    } finally {
        unlink($path);
    }
});

it('imports all mandatory division and location links without duplicating them on retry', function () {
    $rows = territoryRows();
    $rows[0]['division_codes'] = 'DIV-WEST;DIV-SOUTH';
    $rows[0]['cities_and_areas'] = 'Mumbai|Andheri|400058;Mumbai|Bandra|400050';
    $this->service->import($rows);
    $territory = Territory::where('code', 'MUMBAI')->sole();
    expect($territory->divisions()->count())->toBe(2)->and($territory->cityPinCodes()->count())->toBe(2);
    $this->service->import($rows);
    expect($territory->divisions()->count())->toBe(2)->and($territory->cityPinCodes()->count())->toBe(2);
    $changed = $rows;
    $changed[0]['division_codes'] = 'DIV-WEST';
    expect(fn () => $this->service->import($changed))->toThrow(ValidationException::class);
    expect($territory->divisions()->count())->toBe(2);
});

it('rejects missing invalid and ambiguous mandatory references without partial imports', function (string $case) {
    $rows = territoryRows();
    if ($case === 'missing_division') {
        $rows[1]['division_codes'] = '';
    } elseif ($case === 'missing_location') {
        $rows[1]['cities_and_areas'] = '';
    } elseif ($case === 'unknown_division') {
        $rows[1]['division_codes'] = 'DOES-NOT-EXIST';
    } elseif ($case === 'non_division') {
        $type = TypeMaster::create(['name' => 'Department', 'typeable_type' => OrganizationalUnit::class]);
        OrganizationalUnit::where('code', 'DIV-WEST')->update(['type_master_id' => $type->id]);
    } elseif ($case === 'unknown_location') {
        $rows[1]['cities_and_areas'] = 'Mumbai|Missing|400058';
    } elseif ($case === 'invalid_pin') {
        $rows[1]['cities_and_areas'] = 'Mumbai|Andheri|400058abc';
    } elseif ($case === 'ambiguous_location') {
        CityPinCode::create(['city_id' => City::where('name', 'Mumbai')->value('id'), 'area_town' => 'Andheri', 'pin_code' => 400058]);
    } else {
        $rows[1]['name'] = $rows[0]['name'];
    }
    expect(fn () => $this->service->import($rows))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('territories', 0);
})->with(['missing_division', 'missing_location', 'unknown_division', 'non_division', 'unknown_location', 'invalid_pin', 'ambiguous_location', 'duplicate_name']);

it('revalidates mandatory location links at confirmation', function () {
    $preview = $this->service->preview(territoryRows());
    CityPinCode::where('area_town', 'Andheri')->delete();
    expect(fn () => $this->service->import($preview))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('territories', 0);
});
