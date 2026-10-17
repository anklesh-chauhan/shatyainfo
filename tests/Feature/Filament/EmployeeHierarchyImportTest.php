<?php

use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\DataImport\EmployeeImportTemplate;
use App\Services\DataImport\EmployeeOnboardingImporter;
use App\Services\DataImport\ImportResult;
use App\Services\DataImport\ResourceCsvImporter;
use App\Services\DataImport\ResourceImportCatalog;
use Database\Seeders\TypeMasterSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
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

it('resolves an out of order hierarchy and preserves it on repeat imports', function () {
    $rows = app(EmployeeImportTemplate::class)->examples();
    $result = hierarchyUpload($rows);
    expect($result->failed)->toBe([])->and($result->created)->toBe(4)->and($result->hierarchy)->toHaveCount(4);
    expect(Employee::where('employee_id', 'EMP004')->first()->employmentDetail->reportingManager->employee_id)->toBe('EMP003');
    expect(Employee::where('employee_id', 'EMP001')->first()->employmentDetail->reporting_manager_id)->toBeNull();
    expect(hierarchyUpload($rows)->updated)->toBe(4)->and(Employee::count())->toBe(4);
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
