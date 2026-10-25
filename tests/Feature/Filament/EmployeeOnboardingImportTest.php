<?php

use App\Filament\Pages\DataImport;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\EmpDepartment;
use App\Models\EmpGrade;
use App\Models\EmpJobTitle;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataImport\EmployeeOnboardingImporter;
use App\Services\DataImport\ImportRowException;
use App\Services\DataImport\ResourceCsvImporter;
use App\Services\DataImport\ResourceImportCatalog;
use Database\Seeders\TypeMasterSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $this->seed(TypeMasterSeeder::class);
    Notification::fake();
});

it('imports all employee setup from excel and reuses records on repeat upload', function () {
    $columns = EmployeeOnboardingImporter::columns();
    $path = sys_get_temp_dir().'/'.str()->uuid().'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(array_map(fn ($column) => $column->header, $columns)));
    $values = array_map(fn ($column) => $column->example, $columns);
    $values[4] = 'asha@example.test';
    $values[array_search('temporary_password', array_map(fn ($column) => $column->header, $columns))] = 'Temporary-Secret-42';
    $writer->addRow(Row::fromValues($values));
    $writer->close();
    try {
        $module = app(ResourceImportCatalog::class)->findByModel(Employee::class);
        $result = app(ResourceCsvImporter::class)->import($module, $path);
        expect($result->failed)->toBe([])->and($result->created)->toBe(1);
        $repeat = app(ResourceCsvImporter::class)->import($module, $path);
        expect($repeat->failed)->toBe([])->and($repeat->updated)->toBe(1);
        foreach ([Employee::class, EmpDepartment::class, EmpGrade::class, EmpJobTitle::class, JobRole::class, OrganizationalUnit::class, Position::class] as $model) {
            expect($model::query()->count())->toBe(1);
        }
        $employee = Employee::query()->first();
        expect($employee->employmentDetail->department->department_name)->toBe('Sales')
            ->and($employee->positions()->first()->pivot->is_primary)->toBe(1)
            ->and($employee->employmentDetail->organizationalUnits()->count())->toBe(1)
            ->and($employee->login_id)->not->toBeNull();
        $login = User::findOrFail($employee->login_id);
        expect($login->name)->toBe('Asha Shah')->and($login->email)->toBe('asha@example.test')
            ->and($login->employee_id)->toBe($employee->id)
            ->and(Hash::check('Temporary-Secret-42', $login->password))->toBeTrue()
            ->and($login->hasRole('sales_representative'))->toBeTrue()
            ->and($login->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
        $role = Role::findByName('sales_representative');
        expect($role->permissions->pluck('name'))->toContain('Create:Visit')
            ->not->toContain('Delete:Visit');
        $role->givePermissionTo(Permission::findOrCreate('Delete:Visit', 'web'));
        expect(app(ResourceCsvImporter::class)->import($module, $path)->failed)->toBe([]);
        expect($role->fresh()->permissions->pluck('name'))->toContain('Delete:Visit');
        Notification::assertNothingSent();
    } finally {
        unlink($path);
    }
});

it('rolls back the whole employee file when a row is invalid', function () {
    $path = tempnam(sys_get_temp_dir(), 'employee-test-');
    file_put_contents($path, "employee_id,first_name,last_name,mobile_number,department,job_role\nEMP1,Asha,Shah,9000000001,Temporary Sales,Sales Rep\nEMP2,Ravi,Shah,9000000002,,\n");
    try {
        $result = app(ResourceCsvImporter::class)->import(app(ResourceImportCatalog::class)->findByModel(Employee::class), $path);
        expect($result->created)->toBe(0)->and($result->failed)->toHaveCount(1)
            ->and($result->failed[0]['row'])->toBe(2)
            ->and(EmpDepartment::query()->count())->toBe(0)
            ->and(JobRole::query()->count())->toBe(0)
            ->and(Employee::query()->count())->toBe(0);
    } finally {
        unlink($path);
    }
});

it('does not let an unauthorized user import employees', function () {
    $this->actingAs(User::factory()->create());
    expect(fn () => app(EmployeeOnboardingImporter::class)->importRow([
        'employee_id' => 'EMP1', 'first_name' => 'Asha', 'last_name' => 'Shah', 'mobile_number' => '9000000001',
    ]))->toThrow(AuthorizationException::class);
    expect(Employee::query()->count())->toBe(0);
});

it('downloads an Excel template for employee onboarding', function () {
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    try {
        auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
        Livewire::test(DataImport::class)
            ->set('data.resource', EmployeeResource::class)
            ->call('downloadExample')
            ->assertFileDownloaded('employees-import.xlsx');
    } finally {
        Tenant::forgetCurrent();
    }
});

it('does not recreate a deleted employee when its code is imported again', function () {
    $employee = Employee::withoutEvents(fn () => Employee::create([
        'employee_id' => 'DELETED-001', 'first_name' => 'Asha', 'last_name' => 'Shah', 'mobile_number' => '9000000001',
    ]));
    $employee->delete();
    expect(fn () => app(EmployeeOnboardingImporter::class)->importRow([
        'employee_id' => 'DELETED-001', 'first_name' => 'Asha', 'last_name' => 'Shah', 'mobile_number' => '9000000002',
    ]))->toThrow(ImportRowException::class, 'deleted employee');
    expect(Employee::withTrashed()->where('employee_id', 'DELETED-001')->count())->toBe(1);
});

it('rejects conflicting shared setup without changing the existing employee', function () {
    $columns = EmployeeOnboardingImporter::columns();
    $row = collect($columns)->mapWithKeys(fn ($column) => [$column->header => $column->example])->all();
    $path = tempnam(sys_get_temp_dir(), 'employee-conflict-');
    $write = function (array $data) use ($path): void {
        $stream = fopen($path, 'w');
        fputcsv($stream, array_keys($data), ',', '"', '');
        fputcsv($stream, array_values($data), ',', '"', '');
        fclose($stream);
    };
    try {
        $module = app(ResourceImportCatalog::class)->findByModel(Employee::class);
        $write($row);
        expect(app(ResourceCsvImporter::class)->import($module, $path)->created)->toBe(1);
        $write([...$row, 'first_name' => 'Changed', 'department' => 'Conflicting Department']);
        $result = app(ResourceCsvImporter::class)->import($module, $path);
        expect($result->failed)->toHaveCount(1)
            ->and($result->failed[0]['message'])->toContain('conflicting')
            ->and(Employee::query()->sole()->first_name)->toBe('Asha')
            ->and(EmpDepartment::query()->count())->toBe(1);
        $write(['employee_id' => 'EMP001', 'first_name' => 'Updated', 'last_name' => '', 'mobile_number' => '']);
        expect(app(ResourceCsvImporter::class)->import($module, $path)->updated)->toBe(1)
            ->and(Employee::query()->sole()->last_name)->toBe('Shah')
            ->and(Employee::query()->sole()->employmentDetail->department->department_name)->toBe('Sales');
    } finally {
        unlink($path);
    }
});
