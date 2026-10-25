<?php

use App\Filament\Pages\DataImport;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\OrganizationalUnits\Pages\CreateOrganizationalUnit;
use App\Filament\Resources\Positions\Pages\EditPosition;
use App\Models\Address;
use App\Models\EmpDepartment;
use App\Models\Employee;
use App\Models\EmploymentDetail;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\TypeMaster;
use App\Models\User;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\EmployeeImportTemplate;
use App\Services\DataImport\EmployeeOnboardingImporter;
use App\Services\DataImport\ImportResult;
use App\Services\DataImport\ResourceCsvImporter;
use App\Services\DataImport\ResourceImportCatalog;
use App\Services\OrganizationalUnitService;
use Database\Seeders\ConfigDrivenShieldPermissionSeeder;
use Database\Seeders\DepartmentRoleSeeder;
use Database\Seeders\TypeMasterSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('tracks onboarding from saved company and employee data before allowing completion', function () {
    $service = new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    };
    expect($service->checklist())->toMatchArray(['company' => false, 'employees' => false, 'review' => false]);
    expect(fn () => $service->finish())->toThrow(ValidationException::class);
    $service->saveOrganization(['name' => 'Example Company', 'email' => 'company@example.test', 'phone' => '9000000000']);
    expect($service->checklist())->toMatchArray(['company' => true, 'employees' => false, 'review' => false]);
    expect(fn () => $service->finish())->toThrow(ValidationException::class);
    expect(hierarchyUpload(app(EmployeeImportTemplate::class)->examples())->failed)->toBe([]);
    expect($service->checklist())->toMatchArray(['company' => true, 'structure' => true, 'employees' => true, 'review' => false, 'employee_count' => 4]);
    app()->instance(CompanyOnboardingService::class, $service);
    app(OnboardingApprovalService::class)->saveDraft(['required' => 'no']);
    $service->finish();
    expect($service->checklist()['review'])->toBeTrue();
});

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $this->seed(TypeMasterSeeder::class);
});

function hierarchyUpload(array $rows): ImportResult
{
    $headers = array_map(fn ($column) => $column->header, EmployeeOnboardingImporter::columns());
    $path = tempnam(sys_get_temp_dir(), 'hierarchy-test-');
    $stream = fopen($path, 'w');
    fputcsv($stream, $headers, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($stream, array_map(fn ($header) => $row[$header] ?? '', $headers), ',', '"', '');
    }
    fclose($stream);
    try {
        return app(ResourceCsvImporter::class)->import(app(ResourceImportCatalog::class)->findByModel(Employee::class), $path);
    } finally {
        unlink($path);
    }
}

it('requires a temporary password for new employee logins and preserves passwords on rerun', function () {
    $row = app(EmployeeImportTemplate::class)->examples()[1];
    $row['email'] = 'new.employee@example.test';
    $result = hierarchyUpload([$row]);
    expect($result->failed)->toHaveCount(1)->and($result->failed[0]['message'])->toContain('temporary password');
    expect(Employee::count())->toBe(0)->and(Role::where('name', 'sales_head')->exists())->toBeFalse();
    $row['temporary_password'] = ' First-Password-42 ';
    expect(hierarchyUpload([$row])->failed)->toBe([]);
    $employee = Employee::sole();
    $user = User::findOrFail($employee->login_id);
    expect(Hash::check($row['temporary_password'], $user->password))->toBeTrue();
    $original = $user->password;
    $row['temporary_password'] = 'Different-Password-42';
    expect(hierarchyUpload([$row])->failed)->toBe([]);
    expect($user->fresh()->password)->toBe($original)->and($user->hasRole('sales_head'))->toBeTrue();
    unset($row['temporary_password']);
    expect(hierarchyUpload([$row])->failed)->toBe([]);
    expect(User::where('email', $row['email'])->count())->toBe(1);
});

it('rejects login email collisions without linking another users account', function () {
    $row = app(EmployeeImportTemplate::class)->examples()[1];
    $row['email'] = auth()->user()->email;
    $row['temporary_password'] = 'Temporary-Password-42';
    $password = auth()->user()->password;
    $result = hierarchyUpload([$row]);
    expect($result->failed)->toHaveCount(1)->and($result->failed[0]['message'])->toContain('another login');
    expect(Employee::count())->toBe(0)->and(auth()->user()->fresh()->password)->toBe($password);
});

it('seeds only the two administrator roles without deleting imported roles', function () {
    $this->seed(ConfigDrivenShieldPermissionSeeder::class);
    $this->seed(DepartmentRoleSeeder::class);
    expect(Role::orderBy('name')->pluck('name')->all())->toBe(['sales_admin', 'super_admin']);
    Role::findOrCreate('imported_job_role', 'web');
    $this->seed(ConfigDrivenShieldPermissionSeeder::class);
    expect(Role::where('name', 'imported_job_role')->exists())->toBeTrue();
});

it('creates a division from the organizational unit form and requires a valid OU type', function () {
    foreach (['ViewAny:OrganizationalUnit', 'Create:OrganizationalUnit'] as $permission) {
        auth()->user()->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $divisionType = TypeMaster::where('typeable_type', OrganizationalUnit::class)->where('name', 'Division')->sole();
    $page = Livewire::test(CreateOrganizationalUnit::class)
        ->assertSuccessful()
        ->fillForm(['name' => 'West Division', 'code' => 'WEST', 'type_master_id' => null])
        ->call('create')
        ->assertHasFormErrors(['type_master_id' => 'required']);
    $wrongType = TypeMaster::where('typeable_type', Address::class)->where('name', 'Head Office')->sole();
    $page->fillForm(['type_master_id' => $wrongType->id])->call('create')->assertHasFormErrors(['type_master_id']);
    $page->fillForm(['type_master_id' => $divisionType->id])->call('create')->assertHasNoFormErrors();
    expect(OrganizationalUnit::where('code', 'WEST')->sole()->type_master_id)->toBe($divisionType->id);
});

it('resolves an out of order hierarchy and preserves it on repeat imports', function () {
    $rows = app(EmployeeImportTemplate::class)->examples();
    $result = hierarchyUpload($rows);
    expect($result->failed)->toBe([])->and($result->created)->toBe(4)->and($result->hierarchy)->toHaveCount(4);
    expect(Employee::where('employee_id', 'EMP004')->first()->employmentDetail->reportingManager->employee_id)->toBe('EMP003');
    expect(Employee::where('employee_id', 'EMP001')->first()->employmentDetail->reporting_manager_id)->toBeNull();
    $division = OrganizationalUnit::where('name', 'West Division')->sole();
    expect(Position::where('code', 'MR-MUM-01')->sole()->division_ou_id)->toBe($division->id)
        ->and(Employee::where('employee_id', 'EMP004')->sole()->employmentDetail->division_ou_id)->toBe($division->id);
    expect(hierarchyUpload($rows)->updated)->toBe(4)->and(Employee::count())->toBe(4);
});

it('derives the nearest division for nested organizational units', function () {
    $type = fn (string $name) => TypeMaster::where('typeable_type', OrganizationalUnit::class)->where('name', $name)->sole()->id;
    $division = OrganizationalUnit::create(['name' => 'West Division', 'code' => 'WEST', 'type_master_id' => $type('Division')]);
    $plant = OrganizationalUnit::create(['name' => 'Mumbai Plant', 'code' => 'MUM', 'type_master_id' => $type('Plant'), 'parent_id' => $division->id]);
    $unit = OrganizationalUnit::create(['name' => 'Mumbai Team', 'code' => 'TEAM', 'type_master_id' => $type('Department'), 'parent_id' => $plant->id]);
    $rows = app(EmployeeImportTemplate::class)->examples();
    $rows[0]['org_unit'] = $unit->name;
    $rows[0]['org_unit_type'] = 'Department';
    expect(hierarchyUpload($rows)->failed)->toBe([])
        ->and(Position::where('code', 'MR-MUM-01')->sole()->division_ou_id)->toBe($division->id)
        ->and(Employee::where('employee_id', 'EMP004')->sole()->employmentDetail->division_ou_id)->toBe($division->id)
        ->and(OrganizationalUnitService::divisionUnitIds($division->id))->toContain($unit->id, $plant->id, $division->id);
    $detail = Employee::where('employee_id', 'EMP004')->sole()->employmentDetail;
    $detail->load('organizationalUnits');
    $detail->save();
});

it('repairs missing division fields on repeat imports and clears division outside a division hierarchy', function () {
    $rows = app(EmployeeImportTemplate::class)->examples();
    expect(hierarchyUpload($rows)->failed)->toBe([]);
    Position::query()->update(['division_ou_id' => null]);
    EmploymentDetail::query()->update(['division_ou_id' => null]);
    expect(hierarchyUpload($rows)->failed)->toBe([]);
    $position = Position::where('code', 'MR-MUM-01')->sole();
    expect($position->division_ou_id)->not->toBeNull();
    $headOffice = OrganizationalUnit::create(['name' => 'Head Office', 'code' => 'HQ', 'type_master_id' => TypeMaster::where('typeable_type', OrganizationalUnit::class)->where('name', 'Head Office')->sole()->id]);
    $position->update(['organizational_unit_id' => $headOffice->id]);
    expect($position->division_ou_id)->toBeNull();
});

it('rejects a non division selected as a division', function () {
    $unit = OrganizationalUnit::create(['name' => 'Plant', 'code' => 'PLANT', 'type_master_id' => TypeMaster::where('typeable_type', OrganizationalUnit::class)->where('name', 'Plant')->sole()->id]);
    expect(fn () => Position::create(['name' => 'Invalid', 'code' => 'INVALID', 'division_ou_id' => $unit->id]))->toThrow(ValidationException::class);
});

it('rejects organizational units outside the selected division on the position form', function () {
    foreach (['ViewAny:Position', 'View:Position', 'Update:Position'] as $permission) {
        auth()->user()->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    expect(hierarchyUpload(app(EmployeeImportTemplate::class)->examples())->failed)->toBe([]);
    $position = Position::where('code', 'MR-MUM-01')->sole();
    $other = OrganizationalUnit::create(['name' => 'East Division', 'code' => 'EAST', 'type_master_id' => TypeMaster::where('typeable_type', OrganizationalUnit::class)->where('name', 'Division')->sole()->id]);
    Livewire::test(EditPosition::class, ['record' => $position->getRouteKey()])
        ->assertSuccessful()
        ->fillForm(['division_ou_id' => $other->id, 'organizationalUnits' => [$position->organizational_unit_id]])
        ->call('save')
        ->assertHasFormErrors(['organizationalUnits']);
    $position->update(['division_ou_id' => $other->id]);
    expect($position->organizational_unit_id)->toBeNull()->and($position->division_ou_id)->toBe($other->id);
});

it('rejects cycles when resolving a child organizational unit division', function () {
    $type = TypeMaster::where('typeable_type', OrganizationalUnit::class)->where('name', 'Plant')->sole()->id;
    $unit = OrganizationalUnit::create(['name' => 'Cycle', 'code' => 'CYCLE', 'type_master_id' => $type]);
    $unit->update(['parent_id' => $unit->id]);
    expect(fn () => OrganizationalUnitService::divisionId($unit->id))->toThrow(ValidationException::class);
});

it('backfills OU division fields and removes the legacy division schema', function () {
    expect(hierarchyUpload(app(EmployeeImportTemplate::class)->examples())->failed)->toBe([]);
    Position::query()->update(['division_ou_id' => null]);
    EmploymentDetail::query()->update(['division_ou_id' => null]);
    $migration = require database_path('migrations/tenant/2026_10_17_230759_standardize_divisions_as_organizational_units.php');
    $migration->up();
    $division = OrganizationalUnit::where('name', 'West Division')->sole();
    expect(Position::where('code', 'MR-MUM-01')->sole()->division_ou_id)->toBe($division->id)
        ->and(Employee::where('employee_id', 'EMP004')->sole()->employmentDetail->division_ou_id)->toBe($division->id)
        ->and(Schema::hasTable('emp_divisions'))->toBeFalse()
        ->and(Schema::hasColumn('employment_details', 'division_id'))->toBeFalse();
});

it('reuses Sales without changing its organizational unit', function (bool $hasUnit) {
    $existingUnit = $hasUnit ? OrganizationalUnit::create([
        'name' => 'Head Office',
        'code' => 'HQ',
        'type_master_id' => TypeMaster::where('typeable_type', OrganizationalUnit::class)->where('name', 'Division')->sole()->id,
    ]) : null;
    $department = EmpDepartment::create([
        'department_name' => 'Sales',
        'department_code' => 'SALES',
        'organizational_unit_id' => $existingUnit?->id,
    ]);

    $result = hierarchyUpload(app(EmployeeImportTemplate::class)->examples());

    expect($result->failed)->toBe([])->and($result->created)->toBe(4)
        ->and(EmpDepartment::count())->toBe(1)
        ->and($department->fresh()->organizational_unit_id)->toBe($existingUnit?->id);
    $unit = OrganizationalUnit::where('name', 'West Division')->sole();
    expect(Employee::where('employee_id', 'EMP004')->sole()->employmentDetail->organizational_unit_id)->toBe($unit->id)
        ->and(Position::where('code', 'MR-MUM-01')->sole()->organizational_unit_id)->toBe($unit->id)
        ->and(Employee::where('employee_id', 'EMP004')->sole()->employmentDetail->reportingManager->employee_id)->toBe('EMP003');
})->with([false, true]);

it('shares a newly imported department across employee organizational units', function () {
    $rows = app(EmployeeImportTemplate::class)->examples();
    $rows[0]['org_unit'] = 'East Division';

    $result = hierarchyUpload($rows);

    expect($result->failed)->toBe([])->and($result->created)->toBe(4)
        ->and(EmpDepartment::count())->toBe(1)
        ->and(EmpDepartment::sole()->organizational_unit_id)->toBeNull();
});

it('shows persistent escaped row errors in the import notification', function () {
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    Organization::create(['name' => 'Import Company', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    try {
        Storage::fake('local');
        $message = 'Unknown position <MISSING>. No rows were saved.';
        $this->mock(ResourceCsvImporter::class)->shouldReceive('import')->once()
            ->andReturn(new ImportResult(failed: [['row' => 2, 'message' => $message]]));
        $upload = UploadedFile::fake()->createWithContent('employees.csv', "employee_id,first_name\nEMP004,Asha\n");

        Livewire::test(DataImport::class)
            ->fillForm(['resource' => EmployeeResource::class, 'file' => $upload])
            ->call('import')
            ->assertHasNoFormErrors()
            ->assertNotified(Notification::make()
                ->title('Import failed')
                ->body(nl2br(e("0 created, 0 updated. 1 failed.\nRow 2: ".$message)))
                ->danger()->persistent());
    } finally {
        Tenant::forgetCurrent();
    }
});

it('rolls back the whole file for invalid reporting references', function (string $failure) {
    $rows = app(EmployeeImportTemplate::class)->examples();
    if ($failure === 'missing position') {
        $rows[0]['reports_to_position_code'] = 'MISSING';
    } elseif ($failure === 'missing employee') {
        $rows[0]['reports_to_employee_code'] = 'MISSING';
    } elseif ($failure === 'wrong manager') {
        $rows[0]['reports_to_employee_code'] = 'EMP001';
    } elseif ($failure === 'self position') {
        $rows[0]['reports_to_position_code'] = 'MR-MUM-01';
    } elseif ($failure === 'cycle') {
        $rows[1]['reports_to_position_code'] = 'MR-MUM-01';
    } elseif ($failure === 'multiple primary') {
        $rows[] = ['employee_id' => 'EMP004', 'position_code' => 'SALES-HEAD', 'is_primary_position' => 'Yes'];
    } elseif ($failure === 'no primary') {
        $rows[0]['is_primary_position'] = 'No';
    } elseif ($failure === 'ambiguous manager') {
        $rows[] = ['employee_id' => 'EMP004', 'position_code' => 'SALES-HEAD', 'is_primary_position' => 'No'];
    } elseif ($failure === 'self employee') {
        $rows[] = ['employee_id' => 'EMP004', 'position' => 'Vacant parent', 'position_code' => 'PARENT', 'job_role' => 'Manager', 'is_primary_position' => 'No'];
        $rows[0]['reports_to_position_code'] = 'PARENT';
    }
    $result = hierarchyUpload($rows);
    expect($result->failed)->not->toBeEmpty()->and($result->created)->toBe(0)
        ->and($result->failed[0]['message'])->toContain('No rows were saved')
        ->and(Employee::count())->toBe(0)->and(Position::count())->toBe(0);
})->with(['missing position', 'missing employee', 'wrong manager', 'self position', 'cycle', 'multiple primary', 'no primary', 'ambiguous manager', 'self employee']);

it('preserves blanks and clears a reporting link only when explicitly requested', function () {
    expect(hierarchyUpload(app(EmployeeImportTemplate::class)->examples())->failed)->toBe([]);
    $row = ['employee_id' => 'EMP004', 'position_code' => 'MR-MUM-01'];
    expect(hierarchyUpload([$row])->failed)->toBe([]);
    expect(Employee::where('employee_id', 'EMP004')->first()->employmentDetail->reportingManager->employee_id)->toBe('EMP003');
    expect(hierarchyUpload([...[$row + ['reporting_action' => 'clear']]])->failed)->toBe([]);
    expect(Position::where('code', 'MR-MUM-01')->first()->reports_to_position_id)->toBeNull();
    expect(Employee::where('employee_id', 'EMP004')->first()->employmentDetail->reporting_manager_id)->toBeNull();
});

it('refreshes managers when occupants change outside an import and displays vacancies', function () {
    expect(hierarchyUpload(app(EmployeeImportTemplate::class)->examples())->failed)->toBe([]);
    $manager = Employee::where('employee_id', 'EMP003')->first();
    $employee = Employee::where('employee_id', 'EMP004')->first();
    $position = Position::where('code', 'ASM-MUMBAI')->first();
    $manager->positions()->detach($position->id);
    expect($employee->employmentDetail()->first()->reporting_manager_id)->toBeNull();
    $result = hierarchyUpload([['employee_id' => 'EMP004', 'position_code' => 'MR-MUM-01']]);
    expect($result->failed)->toBe([])->and($result->hierarchy[0]['status'])->toBe('Reporting position vacant');
    $replacement = Employee::where('employee_id', 'EMP002')->first();
    $position->employees()->attach($replacement->id, ['is_primary' => false]);
    expect($employee->employmentDetail()->first()->reporting_manager_id)->toBe($replacement->id);
    $replacement->provisionLoginOnSave = false;
    $replacement->update(['is_active' => false]);
    expect($employee->employmentDetail()->first()->reporting_manager_id)->toBeNull();
});

it('changes reporting links in any row order when the final graph is valid', function () {
    expect(hierarchyUpload(app(EmployeeImportTemplate::class)->examples())->failed)->toBe([]);
    $result = hierarchyUpload([
        ['employee_id' => 'EMP001', 'position_code' => 'SALES-HEAD', 'reports_to_position_code' => 'RSM-WEST'],
        ['employee_id' => 'EMP002', 'position_code' => 'RSM-WEST', 'reporting_action' => 'clear'],
    ]);
    expect($result->failed)->toBe([]);
    expect(Employee::where('employee_id', 'EMP001')->first()->employmentDetail->reportingManager->employee_id)->toBe('EMP002');
});

it('provides a blank upload sheet with complete instructions and separate examples', function () {
    $path = sys_get_temp_dir().'/'.str()->uuid().'.xlsx';
    app(EmployeeImportTemplate::class)->write($path);
    $reader = new Reader;
    try {
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = [];
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        expect(array_keys($sheets))->toBe(['Employees', 'Instructions', 'Examples']);
        expect($sheets['Employees'])->toHaveCount(1);
        expect($sheets['Employees'][0])->toContain('reports_to_position_code', 'reports_to_employee_code', 'is_primary_position', 'assignment_action', 'reporting_action');
        $instructions = json_encode($sheets['Instructions']);
        expect($instructions)->toContain('circular', 'clear', 'remove', 'No first matching manager', 'maximum 20');
        $reader->close();
        $import = app(ResourceCsvImporter::class)->import(app(ResourceImportCatalog::class)->findByModel(Employee::class), $path);
        expect($import->failed)->toBe([])->and($import->created)->toBe(0);
        $examples = $sheets['Examples'];
        $headers = array_shift($examples);
        $rows = array_map(fn ($row) => array_combine($headers, array_pad($row, count($headers), '')), $examples);
        expect(hierarchyUpload($rows)->created)->toBe(4);
    } finally {
        $reader->close();
        unlink($path);
    }
});

it('replaces a manager atomically and refreshes reports not included in the file', function () {
    expect(hierarchyUpload(app(EmployeeImportTemplate::class)->examples())->failed)->toBe([]);
    $result = hierarchyUpload([
        ['employee_id' => 'EMP002', 'position_code' => 'ASM-MUMBAI', 'is_primary_position' => 'No'],
        ['employee_id' => 'EMP003', 'position_code' => 'BENCH', 'position' => 'Advisor', 'job_role' => 'Advisor', 'is_primary_position' => 'Yes'],
        ['employee_id' => 'EMP003', 'position_code' => 'ASM-MUMBAI', 'assignment_action' => 'remove'],
    ]);
    expect($result->failed)->toBe([])->and($result->updated)->toBe(2);
    expect(Employee::where('employee_id', 'EMP004')->first()->employmentDetail->reportingManager->employee_id)->toBe('EMP002');
    expect(Employee::where('employee_id', 'EMP003')->first()->positions()->where('code', 'ASM-MUMBAI')->exists())->toBeFalse();
});

it('reads the Employees sheet even when Instructions comes first', function () {
    $path = sys_get_temp_dir().'/'.str()->uuid().'.xlsx';
    $writer = new Writer;
    $headers = array_map(fn ($column) => $column->header, EmployeeOnboardingImporter::columns());
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Instructions');
    $writer->addRow(Row::fromValues(['Not employee data']));
    $writer->addNewSheetAndMakeItCurrent()->setName('Employees');
    $writer->addRow(Row::fromValues($headers));
    foreach (app(EmployeeImportTemplate::class)->examples() as $row) {
        $writer->addRow(Row::fromValues(array_map(fn ($header) => $row[$header] ?? '', $headers)));
    }
    $writer->close();
    try {
        $result = app(ResourceCsvImporter::class)->import(app(ResourceImportCatalog::class)->findByModel(Employee::class), $path);
        expect($result->failed)->toBe([])->and($result->created)->toBe(4);
    } finally {
        unlink($path);
    }
});

it('requires permission to change existing position reporting', function () {
    expect(hierarchyUpload(app(EmployeeImportTemplate::class)->examples())->failed)->toBe([]);
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('Update:Employee', 'web'));
    $this->actingAs($user);
    $result = hierarchyUpload([['employee_id' => 'EMP004', 'position_code' => 'MR-MUM-01', 'reporting_action' => 'clear']]);
    expect($result->failed)->not->toBeEmpty()->and($result->failed[0]['message'])->toContain('permission');
    expect(Position::where('code', 'MR-MUM-01')->first()->reports_to_position_id)->not->toBeNull();
});

it('rejects position cycles from normal model edits too', function () {
    expect(hierarchyUpload(app(EmployeeImportTemplate::class)->examples())->failed)->toBe([]);
    $head = Position::where('code', 'SALES-HEAD')->first();
    $child = Position::where('code', 'MR-MUM-01')->first();
    expect(fn () => $head->update(['reports_to_position_id' => $child->id]))->toThrow(ValidationException::class);
    expect($head->fresh()->reports_to_position_id)->toBeNull();
});
