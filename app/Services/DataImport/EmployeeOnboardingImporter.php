<?php

namespace App\Services\DataImport;

use App\Models\EmpDepartment;
use App\Models\EmpGrade;
use App\Models\EmpJobTitle;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\TypeMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeOnboardingImporter
{
    /** @return list<ImportColumnDefinition> */
    public static function columns(): array
    {
        $examples = [
            'employee_id' => 'EMP001', 'first_name' => 'Asha', 'last_name' => 'Shah',
            'mobile_number' => '9000000001', 'email' => '',
            'department' => 'Sales', 'job_role' => 'Sales Representative',
            'job_role_level' => '1', 'position' => 'Sales Representative - West',
            'position_code' => 'SR-WEST', 'grade' => 'G1', 'job_title' => 'Sales Executive',
            'org_unit' => 'West Division', 'org_unit_type' => 'Division',
            'hire_date' => '2026-10-01', 'employment_type' => 'Permanent',
        ];

        return collect($examples)->map(fn (string $example, string $header): ImportColumnDefinition => new ImportColumnDefinition(
            header: $header, attribute: $header, label: Str::headline($header), type: 'string',
            required: in_array($header, ['employee_id', 'first_name', 'last_name', 'mobile_number']), example: $example,
        ))->values()->all();
    }

    /** @param array<string, mixed> $row */
    public function importRow(array $row): string
    {
        $row = array_filter($row, fn (mixed $value): bool => filled($value));
        $employee = Employee::query()->firstOrNew(['employee_id' => $row['employee_id'] ?? '']);
        $existing = $employee->exists;
        $this->authorize($existing ? 'update' : 'create', $existing ? $employee : Employee::class);
        $rules = [
            'employee_id' => ['required', 'string', 'max:20'],
            'first_name' => [$existing ? 'sometimes' : 'required', 'string', 'max:50'],
            'last_name' => [$existing ? 'sometimes' : 'required', 'string', 'max:50'],
            'mobile_number' => [$existing ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('employees')->ignore($employee->id)],
            'email' => ['sometimes', 'email', 'max:100', Rule::unique('employees')->ignore($employee->id)],
            'department' => ['sometimes', 'string', 'max:100'],
            'grade' => ['sometimes', 'string', 'max:50'],
            'job_role_level' => ['sometimes', 'integer', 'min:1'],
            'hire_date' => ['sometimes', 'date_format:Y-m-d'],
            'employment_type' => ['sometimes', Rule::in(['Permanent', 'Contract', 'Part-Time', 'Intern', 'Temporary', 'Consultant'])],
        ];
        foreach (['job_role', 'position', 'position_code', 'job_title', 'org_unit', 'org_unit_type'] as $field) {
            $rules[$field] = ['sometimes', 'string', 'max:255'];
        }
        Validator::make($row, $rules)->validate();
        $unit = null;
        if (isset($row['org_unit'])) {
            $type = TypeMaster::query()->where('typeable_type', OrganizationalUnit::class)->where('name', $row['org_unit_type'] ?? 'Division')->first();
            if (! $type) {
                throw new ImportRowException('Org unit type was not found. Choose an existing organizational unit type.');
            }
            $unit = $this->master(OrganizationalUnit::class, ['name' => $row['org_unit']], ['code' => 'OU-'.Str::uuid(), 'type_master_id' => $type->id], ['type_master_id']);
        }
        $department = isset($row['department']) ? $this->master(EmpDepartment::class, ['department_name' => $row['department']], array_filter(['department_code' => 'DEP-'.Str::uuid(), 'organizational_unit_id' => $unit?->id]), ['organizational_unit_id']) : null;
        $grade = isset($row['grade']) ? $this->master(EmpGrade::class, ['grade_name' => $row['grade']], array_filter(['department_id' => $department?->id]), ['department_id']) : null;
        $title = isset($row['job_title']) ? $this->master(EmpJobTitle::class, ['title' => $row['job_title']], array_filter(['department_id' => $department?->id]), ['department_id']) : null;
        $role = isset($row['job_role']) ? $this->master(JobRole::class, ['name' => $row['job_role']], ['code' => 'ROLE-'.Str::uuid(), 'level' => $row['job_role_level'] ?? 1], isset($row['job_role_level']) ? ['level'] : []) : null;
        $position = null;
        if (isset($row['position']) || isset($row['position_code'])) {
            if (! isset($row['position'], $row['position_code']) || ! $role) {
                throw new ImportRowException('Position requires position, position_code and job_role.');
            }
            $attributes = array_filter(['name' => $row['position'], 'job_role_id' => $role->id, 'department_id' => $department?->id, 'job_title_id' => $title?->id, 'job_grade_id' => $grade?->id, 'organizational_unit_id' => $unit?->id]);
            $position = $this->master(Position::class, ['code' => $row['position_code']], $attributes, array_keys($attributes));
        } elseif ($role) {
            throw new ImportRowException('Add position and position_code to assign the job role to this employee.');
        }
        $employee->fill(array_intersect_key($row, array_flip(['employee_id', 'first_name', 'last_name', 'mobile_number', 'email'])));
        $employee->provisionLoginOnSave = false;
        $employee->save();
        if ($position) {
            if (! $employee->positions()->whereKey($position->id)->exists()) {
                $employee->positions()->attach($position->id, ['is_primary' => ! $employee->positions()->wherePivot('is_primary', true)->exists()]);
            }
            if ($unit) {
                $position->organizationalUnits()->syncWithoutDetaching([$unit->id]);
            }
        }
        $employment = array_filter(['department_id' => $department?->id, 'grade_id' => $grade?->id, 'job_title_id' => $title?->id, 'organizational_unit_id' => $unit?->id, 'hire_date' => $row['hire_date'] ?? null, 'employment_type' => $row['employment_type'] ?? null], fn (mixed $value): bool => $value !== null);
        if ($employment !== []) {
            $detail = $employee->employmentDetail()->updateOrCreate([], $employment);
            if ($unit) {
                $detail->organizationalUnits()->syncWithoutDetaching([$unit->id]);
            }
        }

        return $existing ? 'updated' : 'created';
    }

    /** @param class-string<Model> $class */
    private function master(string $class, array $key, array $defaults, array $consistent): Model
    {
        $matches = $class::query()->where($key)->limit(2)->get();
        if ($matches->count() > 1) {
            throw new ImportRowException('Multiple '.class_basename($class).' records match this row. Resolve duplicate names first.');
        }
        $record = $matches->first();
        if ($record) {
            foreach ($consistent as $attribute) {
                if (isset($defaults[$attribute]) && (string) $record->{$attribute} !== (string) $defaults[$attribute]) {
                    throw new ImportRowException(class_basename($class).' has conflicting '.$attribute.'. Use the existing setup or a different name/code.');
                }
            }

            return $record;
        }
        $this->authorize('create', $class);

        return $class::query()->create([...$key, ...$defaults]);
    }

    private function authorize(string $ability, Model|string $record): void
    {
        $user = auth()->user();
        if ($user && ($user->hasRole('super_admin') || $user->hasRole('administration_admin') || $user->can('AccessAllRecords'))) {
            return;
        }
        Gate::authorize($ability, $record);
    }
}
