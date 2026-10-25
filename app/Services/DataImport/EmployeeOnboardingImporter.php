<?php

namespace App\Services\DataImport;

use App\Models\EmpDepartment;
use App\Models\EmpGrade;
use App\Models\EmpJobTitle;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Territory;
use App\Models\TypeMaster;
use App\Models\User;
use App\Services\OrganizationalUnitService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
            'position_code' => 'SR-WEST', 'territory_codes' => '', 'grade' => 'G1', 'job_title' => 'Sales Executive',
            'org_unit' => 'West Division', 'org_unit_type' => 'Division',
            'hire_date' => '2026-10-01', 'employment_type' => 'Permanent',
            'is_primary_position' => 'Yes', 'reports_to_position_code' => '',
            'reports_to_employee_code' => '', 'reporting_action' => '', 'assignment_action' => '',
            'temporary_password' => '',
        ];

        return collect($examples)->map(fn (string $example, string $header): ImportColumnDefinition => new ImportColumnDefinition(
            header: $header, attribute: $header, label: Str::headline($header), type: 'string',
            required: in_array($header, ['employee_id', 'first_name', 'last_name', 'mobile_number']), example: $example,
        ))->values()->all();
    }

    /** @param array<string, mixed> $row */
    public function importRow(array $row, bool $deferAssignments = false): string
    {
        $row = array_filter($row, fn (mixed $value): bool => filled($value));
        $matches = Employee::withTrashed()->where('employee_id', $row['employee_id'] ?? '')->limit(2)->get();
        if ($matches->count() > 1) {
            throw new ImportRowException('Multiple employees match this employee code. Resolve duplicates before importing.');
        }
        if ($matches->first()?->trashed()) {
            throw new ImportRowException('This employee code belongs to a deleted employee. Restore or review that employee before importing.');
        }
        $employee = $matches->first() ?? new Employee(['employee_id' => $row['employee_id'] ?? '']);
        $existing = $employee->exists;
        $this->authorize($existing ? 'update' : 'create', $existing ? $employee : Employee::class);
        $rules = [
            'employee_id' => ['required', 'string', 'max:20'],
            'first_name' => [$existing ? 'sometimes' : 'required', 'string', 'max:50'],
            'last_name' => [$existing ? 'sometimes' : 'required', 'string', 'max:50'],
            'mobile_number' => [$existing ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('employees')->ignore($employee->id)],
            'email' => ['sometimes', 'email', 'max:100', Rule::unique('employees')->ignore($employee->id)],
            'department' => ['sometimes', 'string', 'max:100'],
            'territory_codes' => ['sometimes', 'string', 'max:5000'],
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
        $department = isset($row['department']) ? $this->master(EmpDepartment::class, ['department_name' => $row['department']], ['department_code' => 'DEP-'.Str::uuid()], []) : null;
        $grade = isset($row['grade']) ? $this->master(EmpGrade::class, ['grade_name' => $row['grade']], array_filter(['department_id' => $department?->id]), ['department_id']) : null;
        $title = isset($row['job_title']) ? $this->master(EmpJobTitle::class, ['title' => $row['job_title']], array_filter(['department_id' => $department?->id]), ['department_id']) : null;
        $role = isset($row['job_role']) ? $this->master(JobRole::class, ['name' => $row['job_role']], ['code' => 'ROLE-'.Str::uuid(), 'level' => $row['job_role_level'] ?? 1], isset($row['job_role_level']) ? ['level'] : []) : null;
        $position = null;
        if (isset($row['position']) || isset($row['position_code'])) {
            $existingPosition = isset($row['position_code']) ? Position::query()->where('code', $row['position_code'])->first() : null;
            if (! isset($row['position_code']) || (! $existingPosition && (! isset($row['position']) || ! $role))) {
                throw new ImportRowException('Position requires position, position_code and job_role.');
            }
            $attributes = array_filter(['name' => $row['position'] ?? null, 'job_role_id' => $role?->id, 'department_id' => $department?->id, 'job_title_id' => $title?->id, 'job_grade_id' => $grade?->id, 'organizational_unit_id' => $unit?->id]);
            $position = $this->master(Position::class, ['code' => $row['position_code']], $attributes, array_keys($attributes));
        } elseif ($role) {
            throw new ImportRowException('Add position and position_code to assign the job role to this employee.');
        }

        if (isset($row['territory_codes'])) {
            if (! $position || strtolower($row['assignment_action'] ?? '') === 'remove') {
                throw new ImportRowException('Territory codes require a position assignment.');
            }
            $codes = array_values(array_unique(array_map(fn (string $code): string => strtoupper(trim($code)), explode(';', $row['territory_codes']))));
            if (in_array('', $codes, true)) {
                throw new ImportRowException('Separate territory codes with a semicolon without empty entries.');
            }
            $territories = Territory::query()->whereIn('code', $codes)->get();
            $found = $territories->map(fn (Territory $territory): string => strtoupper($territory->code))->all();
            if (array_diff($codes, $found) !== []) {
                throw new ImportRowException('Unknown territory codes: '.implode(', ', array_diff($codes, $found)).'. Import territories first.');
            }
            $ids = $territories->modelKeys();
            sort($ids);
            $currentIds = $position->territories()->pluck('territories.id')->all();
            sort($currentIds);
            if ($currentIds !== [] && $currentIds !== $ids) {
                throw new ImportRowException('Position '.$position->code.' has conflicting territory assignments. Edit it in Positions first.');
            }
            if ($currentIds === []) {
                $this->authorize('update', $position);
                if ($position->approval_status === 'approved') {
                    throw new ImportRowException('Approved position territory assignments must be changed through Positions.');
                }
                $position->territories()->syncWithoutDetaching($ids);
            }
        }
        if ($position && $unit) {
            $divisionId = OrganizationalUnitService::divisionId($unit->id);
            if ($position->division_ou_id !== $divisionId) {
                $this->authorize('update', $position);
                $position->update(['division_ou_id' => $divisionId]);
            }
        }
        $employee->fill(array_intersect_key($row, array_flip(['employee_id', 'first_name', 'last_name', 'mobile_number', 'email'])));
        $employee->provisionLoginOnSave = false;
        $employee->save();
        if ($position && ! $deferAssignments) {
            if (! $employee->positions()->whereKey($position->id)->exists()) {
                $employee->positions()->attach($position->id, ['is_primary' => ! $employee->positions()->wherePivot('is_primary', true)->exists()]);
            }
            if ($unit) {
                $position->organizationalUnits()->syncWithoutDetaching([$unit->id]);
            }
        }
        $employment = array_filter(['department_id' => $department?->id, 'grade_id' => $grade?->id, 'job_title_id' => $title?->id, 'organizational_unit_id' => $unit?->id, 'hire_date' => $row['hire_date'] ?? null, 'employment_type' => $row['employment_type'] ?? null], fn (mixed $value): bool => $value !== null);
        if ($unit) {
            $employment['division_ou_id'] = OrganizationalUnitService::divisionId($unit->id);
        }
        if ($employment !== [] && ! $deferAssignments) {
            $detail = $employee->employmentDetail()->updateOrCreate([], $employment);
            if ($unit) {
                $detail->organizationalUnits()->syncWithoutDetaching([$unit->id]);
            }
        }
        if (! $deferAssignments) {
            $this->provisionLogin($employee, $row);
        }

        return $existing ? 'updated' : 'created';
    }

    public function provisionLogin(Employee $employee, array $row): void
    {
        $roles = [];
        foreach ($employee->positions()->with('jobRole')->get() as $position) {
            if (! $position->jobRole) {
                continue;
            }
            $name = Str::slug($position->jobRole->name, '_');
            if ($name === '') {
                throw new ImportRowException('Job role must contain a usable role name.');
            }
            if (in_array($name, ['super_admin', 'sales_admin', 'administration_admin'], true) && ! auth()->user()->hasRole('super_admin')) {
                throw new ImportRowException('Only Super Admin can assign an administrator role through employee import.');
            }
            $this->authorize('create', \App\Models\Role::class);
            $loginRole = Role::findOrCreate($name, (new User)->getGuardName());
            $this->assignConfiguredPermissions($loginRole);
            $roles[] = $loginRole;
        }
        $user = $employee->login_id ? User::find($employee->login_id) : null;
        $email = $employee->email;
        if (! $email) {
            if (filled($row['temporary_password'] ?? null)) {
                throw new ImportRowException('An email is required when supplying temporary_password.');
            }

            return;
        }
        $this->authorize($user ? 'update' : 'create', $user ?? User::class);
        if (User::where('email', $email)->when($user, fn ($query) => $query->whereKeyNot($user->id))->exists()) {
            throw new ImportRowException('This email already belongs to another login. Link or correct the existing user before importing.');
        }
        if ($user && (($user->employee_id && (int) $user->employee_id !== (int) $employee->id) || Employee::withTrashed()->where('login_id', $user->id)->whereKeyNot($employee->id)->exists())) {
            throw new ImportRowException('This login is already linked to another employee. Correct the link before importing.');
        }
        if (! $user) {
            Validator::make($row, ['temporary_password' => ['required', 'string', 'min:8', 'max:72']])->validate();
            $user = new User(['password' => $row['temporary_password']]);
        }
        $user->fill(['name' => trim($employee->first_name.' '.$employee->last_name), 'email' => $email, 'employee_id' => $employee->id]);
        $user->save();
        $employee->forceFill(['login_id' => $user->id])->saveQuietly();
        foreach ($roles as $role) {
            $user->assignRole($role);
        }
    }

    private function assignConfiguredPermissions(Role $role): void
    {
        $profile = config('permission-map.job_role_profiles.'.$role->name);
        if ($profile === null || $role->permissions()->exists()) {
            return;
        }

        $rules = config('permission-map.roles.'.$profile);
        if (! is_array($rules) || isset($rules['*'])) {
            throw new ImportRowException('Permission profile for '.$role->name.' is invalid. Contact your administrator.');
        }

        $resources = collect(Filament::getPanels())
            ->flatMap(fn ($panel) => $panel->getResources())
            ->unique()
            ->mapWithKeys(fn ($resource): array => [class_basename($resource) => class_basename($resource::getModel())]);
        $permissions = [];
        foreach ($rules as $resourceClass => $level) {
            $model = $resources->get($resourceClass);
            if (! $model) {
                throw new ImportRowException('Permission resource '.$resourceClass.' is unavailable. Contact your administrator.');
            }
            $actions = match ($level) {
                'R' => ['ViewAny', 'View', 'ViewOwnTerritory', 'ViewOwnOU', 'ViewOwn'],
                'C' => ['ViewAny', 'View', 'Create', 'Update', 'ViewOwnTerritory', 'ViewOwnOU', 'ViewOwn'],
                'F' => ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder', 'ViewOwnTerritory', 'ViewOwnOU', 'ViewOwn'],
                default => throw new ImportRowException('Permission level for '.$resourceClass.' is invalid. Contact your administrator.'),
            };
            foreach ($actions as $action) {
                $permissions[] = Permission::findOrCreate($action.':'.$model, $role->guard_name);
            }
        }
        $role->syncPermissions($permissions);
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

    public function authorize(string $ability, Model|string $record): void
    {
        $user = auth()->user();
        if ($user && ($user->hasRole('super_admin') || $user->hasRole('administration_admin') || $user->can('AccessAllRecords'))) {
            return;
        }
        Gate::authorize($ability, $record);
    }
}
