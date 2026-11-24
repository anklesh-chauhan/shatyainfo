<?php

namespace App\Services\HR;

use App\Models\ApprovalOverride;
use App\Models\ApprovalSetting;
use App\Models\BankDetail;
use App\Models\Country;
use App\Models\DailyAttendance;
use App\Models\EmpDepartment;
use App\Models\EmpGrade;
use App\Models\EmpJobTitle;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeShift;
use App\Models\EmploymentDetail;
use App\Models\EmpProfessionalTax;
use App\Models\EmpQualification;
use App\Models\EmpSalary;
use App\Models\EmpSkill;
use App\Models\EmpStatutoryId;
use App\Models\HrChangeRequest;
use App\Models\LeaveApplication;
use App\Models\LocationMaster;
use App\Models\OrganizationalUnit;
use App\Models\PayrollLeaveSnapshot;
use App\Models\Position;
use App\Models\ShiftMaster;
use App\Models\User;
use App\Models\WeekOff;
use App\Notifications\UserAccountCreated;
use App\Services\EmployeeReportingService;
use App\Services\OrganizationalUnitService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class EmployeeRegister
{
    public const SECTIONS = [
        'employment' => ['employmentDetail', EmploymentDetail::class], 'professional-tax' => ['professionalTax', EmpProfessionalTax::class],
        'statutory' => ['statutoryIds', EmpStatutoryId::class], 'qualifications' => ['qualifications', EmpQualification::class], 'skills' => ['skills', EmpSkill::class],
        'shifts' => ['shiftAssignments', EmployeeShift::class], 'week-offs' => ['weekOffs', WeekOff::class], 'banks' => ['bankDetail', BankDetail::class], 'positions' => ['positions', Position::class],
    ];

    public const OPTIONS = ['countries' => [Country::class, 'name'], 'users' => [User::class, 'email'], 'managers' => [Employee::class, 'first_name'],
        'departments' => [EmpDepartment::class, 'department_name'], 'job-titles' => [EmpJobTitle::class, 'title'], 'grades' => [EmpGrade::class, 'grade_name'],
        'divisions' => [OrganizationalUnit::class, 'name'], 'org-units' => [OrganizationalUnit::class, 'name'], 'locations' => [LocationMaster::class, 'name'],
        'positions' => [Position::class, 'name'], 'shifts' => [ShiftMaster::class, 'name']];

    public function canRead(User $actor): bool
    {
        return $actor->can(['ViewAny:Employee', 'View:Employee']);
    }

    public function authorize(User $actor): void
    {
        abort_unless($this->canRead($actor), 403);
    }

    public function visible(User $actor): Builder
    {
        return Employee::query()->withTrashed()->applyVisibility('Employee', $actor)
            ->when(! $this->canRead($actor), fn (Builder $query) => $query->whereKey([]));
    }

    public function find(User $actor, int $id, bool $lock = false): Employee
    {
        $this->authorize($actor);

        return $this->visible($actor)->with('approval')->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function unlocked(User $actor, Model $record): bool
    {
        $states = [$record->getAttribute('approval_status'), method_exists($record, 'getApprovalStatus') ? $record->getApprovalStatus() : null];

        return ! (method_exists($record, 'trashed') && $record->trashed()) && ! in_array('pending', $states, true)
            && (! in_array('approved', $states, true) || $actor->can('OverrideApproval:'.class_basename($record)));
    }

    public function canChange(User $actor, Employee $record, string $permission = 'Update'): bool
    {
        return $this->canRead($actor) && $actor->can($permission.':Employee') && $this->unlocked($actor, $record);
    }

    public function canReadSection(User $actor, string $section): bool
    {
        $name = class_basename(self::SECTIONS[$section][1]);

        return $this->canRead($actor) && $actor->can(['ViewAny:'.$name, 'View:'.$name]);
    }

    public function canWriteSection(User $actor, Employee $employee, string $section, string $ability): bool
    {
        $name = class_basename(self::SECTIONS[$section][1]);

        return $this->canReadSection($actor, $section) && $this->canChange($actor, $employee) && $actor->can($ability.':'.$name);
    }

    public function catalog(User $actor, string $kind, ?Employee $employee = null, ?int $division = null): Builder
    {
        [$class] = self::OPTIONS[$kind];
        $query = $class::query();
        if ($kind === 'users') {
            return $query->when(! $actor->can(['ViewAny:User', 'View:User', 'Update:User']), fn (Builder $query) => $query->whereKey([]))
                ->where(fn (Builder $query) => $query->whereDoesntHave('employee')->when($employee?->login_id, fn (Builder $query, int $loginId) => $query->orWhereKey($loginId)))->whereNotIn('id', Employee::withTrashed()->whereNotNull('login_id')->when($employee, fn (Builder $query) => $query->whereKeyNot($employee->id))->select('login_id'));
        }
        if ($kind !== 'countries') {
            $query->applyVisibility(class_basename($class), $actor);
        }
        if ($kind === 'managers') {
            $query->where('is_active', true)->when($employee, fn (Builder $query) => $query->whereKeyNot($employee->id));
        } elseif ($kind === 'divisions') {
            $query->whereHas('typeMaster', fn (Builder $query) => $query->where('name', 'Division'));
        } elseif ($kind === 'org-units') {
            $query->whereIn('id', $division === null ? [] : OrganizationalUnitService::divisionUnitIds($division));
        } elseif ($kind === 'positions') {
            $query->when(! $actor->can(['ViewAny:Position', 'View:Position']), fn (Builder $query) => $query->whereKey([]));
        }

        return $query;
    }

    public function options(User $actor, string $kind, array $input): array
    {
        $this->authorize($actor);
        $employee = isset($input['employee_id']) ? $this->find($actor, $input['employee_id']) : null;
        $query = $this->catalog($actor, $kind, $employee, $input['division_ou_id'] ?? null);
        $column = self::OPTIONS[$kind][1];
        $search = trim((string) ($input['search'] ?? ''));
        $query->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($kind, $column, $search): void {
            $query->whereLike($column, '%'.$search.'%');
            if ($kind === 'managers') {
                $query->orWhereLike('last_name', '%'.$search.'%')->orWhereLike('employee_id', '%'.$search.'%');
            }
        }));
        $page = $query->orderBy($column)->orderBy('id')->paginate(30);

        return ['data' => $page->getCollection()->map(fn (Model $row): array => ['id' => $row->id, 'label' => $kind === 'managers' ? $row->full_name.' ('.$row->employee_id.')' : $row->{$column}])->all(),
            'meta' => ['last_page' => $page->lastPage()]];
    }

    public function createGrade(User $actor, string $name): EmpGrade
    {
        $this->authorize($actor);
        abort_unless($actor->can('Create:EmpGrade'), 403);
        $input = Validator::make(['grade_name' => $name], ['grade_name' => ['required', 'string', 'max:50']])->validate();

        return EmpGrade::query()->create($input);
    }

    public function revision(Employee $employee): string
    {
        $state = [$employee->getAttributes(), $employee->approval()->first()?->getAttributes()];
        foreach (self::SECTIONS as $section => [$relation, $class]) {
            $query = $employee->{$relation}();
            if ($section !== 'positions') {
                $query->withTrashed();
            }
            if (method_exists(new $class, 'approval')) {
                $query->with('approval');
            }
            if ($section === 'employment') {
                $query->with('organizationalUnits');
            }
            $state[$section] = $query->orderBy($query->getRelated()->qualifyColumn('id'))->get()->map(fn (Model $row): array => [$row->getAttributes(),
                method_exists($row, 'approval') ? $row->approval?->getAttributes() : null,
                $section === 'positions' ? $row->pivot->getAttributes() : ($section === 'employment' ? $row->organizationalUnits->sortBy('id')->map(fn (Model $unit): array => [$unit->id, $unit->pivot->getAttributes()])->values()->all() : null)])->all();
        }
        $state['changes'] = HrChangeRequest::query()->where('employee_id', $employee->id)->orderBy('id')->get()->map->getAttributes()->all();

        return hash('sha256', json_encode(Arr::sortRecursive($state), JSON_THROW_ON_ERROR));
    }

    public function listQuery(User $actor, array $filters): Builder
    {
        $this->authorize($actor);
        $query = $this->visible($actor)->with('approval');
        $search = trim((string) ($filters['search'] ?? ''));
        $query->when($search !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($search): void {
            foreach (['employee_id', 'first_name', 'middle_name', 'last_name', 'email', 'mobile_number'] as $field) {
                $match->orWhereLike($field, '%'.$search.'%');
            }
        }));
        match ($filters['trash'] ?? 'all') {
            'active' => $query->withoutTrashed(), 'trashed' => $query->onlyTrashed(), default => null
        };
        if (isset($filters['active'])) {
            $query->where('is_active', $filters['active'] === '1');
        }
        foreach (['gender', 'country_id'] as $field) {
            $query->when($filters[$field] ?? null, fn (Builder $query, mixed $value) => $query->where($field, $value));
        }
        foreach (['employment_status', 'employment_type', 'department_id', 'job_title_id', 'grade_id', 'division_ou_id'] as $field) {
            $query->when($filters[$field] ?? null, fn (Builder $query, mixed $value) => $query->whereHas('employmentDetail', fn (Builder $employment) => $employment->where($field, $value)));
        }
        foreach (['hire' => 'hire_date', 'increment' => 'last_increment_date'] as $prefix => $column) {
            foreach (['from' => '>=', 'to' => '<='] as $suffix => $operator) {
                $query->when($filters[$prefix.'_'.$suffix] ?? null, fn (Builder $query, string $value) => $query->whereHas('employmentDetail', fn (Builder $employment) => $employment->whereDate($column, $operator, $value)));
            }
        }
        $query->when($filters['org_unit_ids'] ?? [], fn (Builder $query, array $ids) => $query->whereHas('employmentDetail.organizationalUnits', fn (Builder $units) => $units->whereKey($ids)));

        return $query;
    }

    public function summary(User $actor, Employee $employee): array
    {
        return [...$employee->only(['id', 'employee_id', 'first_name', 'middle_name', 'last_name', 'email', 'mobile_number', 'is_active']),
            'full_name' => $employee->full_name, 'trashed' => $employee->trashed(), 'approval_status' => $employee->getApprovalStatus() ?? $employee->approval_status,
            'actions' => array_values(array_filter(['submit', 'delete', 'restore'], fn (string $action): bool => $this->canAct($actor, $employee, $action)))];
    }

    public function detail(User $actor, Employee $employee): array
    {
        $fields = array_keys(EmployeeRegisterFields::fields('employee'));
        $data = [...$this->summary($actor, $employee), ...$employee->only(array_diff($fields, ['provision_login'])),
            'date_of_birth' => $employee->date_of_birth?->format('Y-m-d'), 'provision_login' => false, 'revision' => $this->revision($employee),
            ...$this->reportingSummary($actor, $employee),
            'has_image' => filled($employee->profile_picture), 'can_edit' => $this->canChange($actor, $employee),
            'sections' => array_values(array_filter(array_keys(self::SECTIONS), fn (string $section): bool => $this->canReadSection($actor, $section))),
            'fields' => $this->formFields($actor, 'employee'), 'change_fields' => array_values(array_filter($this->formFields($actor, 'employee'), fn (array $field): bool => array_key_exists($field['key'], HrChangeService::rules('employee')))), 'can_request_change' => $this->canRequestChange($actor, $employee),
        ];
        $data['labels'] = $this->labels($actor, $employee, 'employee', $data);
        if ($actor->can(['ViewAny:HrChangeRequest', 'View:HrChangeRequest'])) {
            $data['hr_changes'] = HrChangeRequest::query()->where('employee_id', $employee->id)->latest('id')->limit(30)->get(['id', 'request_type', 'reason', 'approval_status', 'applied_at'])->toArray();
        }

        return $data;
    }

    public function reportingSummary(User $actor, Employee $employee): array
    {
        $resolution = app(EmployeeReportingService::class)->resolve($employee);
        $manager = $resolution['manager'] === null ? null : $this->visible($actor)->withoutTrashed()->find($resolution['manager']);

        return ['reporting_manager' => $manager?->full_name, 'reporting_manager_code' => $manager?->employee_id];
    }

    public function formFields(User $actor, string $section): array
    {
        return array_values(array_map(function (array $field) use ($actor, $section): array {
            $field['requires_hr_change'] = in_array($section, ['employee', 'employment'], true) && ApprovalSetting::moduleRequiresApproval(HrChangeRequest::class)
                && array_key_exists($field['key'], HrChangeService::rules($section));
            if ($field['key'] === 'grade_id') {
                $field['can_create'] = $actor->can('Create:EmpGrade');
            }
            if ($field['key'] === 'provision_login') {
                $field['can_set'] = $actor->can('Create:User');
            }

            return $field;
        }, EmployeeRegisterFields::fields($section)));
    }

    private function labels(User $actor, Employee $employee, string $section, array $values): array
    {
        $labels = [];
        foreach (EmployeeRegisterFields::fields($section) as $key => $field) {
            if (! in_array($field['type'], ['picker', 'multi'], true)) {
                continue;
            }
            $ids = array_filter((array) ($values[$key] ?? []));
            $rows = $this->catalog($actor, $field['kind'], $employee, isset($values['division_ou_id']) ? (int) $values['division_ou_id'] : null)->whereKey($ids)->get();
            foreach ($rows as $row) {
                $labels[$key][(string) $row->id] = $field['kind'] === 'managers' ? $row->full_name : $row->{self::OPTIONS[$field['kind']][1]};
            }
        }

        return $labels;
    }

    public function save(User $actor, ?int $id, array $input): Employee
    {
        $this->authorize($actor);
        $input = Validator::make($input, EmployeeRegisterFields::rules('employee') + ['revision' => [$id === null ? 'prohibited' : 'required', 'string', 'size:64']])->validate();
        $stored = null;
        try {
            return (new Employee)->getConnection()->transaction(function () use ($actor, $id, $input, &$stored): Employee {
                $record = $id === null ? new Employee : $this->find($actor, $id, true);
                abort_unless($id === null ? $actor->can('Create:Employee') : $this->canChange($actor, $record), 403);
                if ($id !== null) {
                    $this->checkRevision($record, $input['revision']);
                }
                foreach (['employee_id', 'email', 'mobile_number'] as $field) {
                    if (Employee::withTrashed()->where($field, $input[$field])->when($id, fn (Builder $query) => $query->whereKeyNot($id))->exists()) {
                        throw ValidationException::withMessages([$field => 'This '.$field.' is already used by an employee, including deleted employees.']);
                    }
                }
                $this->validateChoices($actor, $record, 'employee', $input, $record);
                $login = $input['login_id'] ?? null;
                if ((int) $login !== (int) $record->login_id) {
                    abort_if($record->login_id === $actor->id || ($record->exists && $this->hasHistory($record)), 409, 'A linked login with employee history cannot be reassigned.');
                    abort_unless($actor->can(['ViewAny:User', 'View:User', 'Update:User']), 403);
                    if ($login !== null) {
                        $user = User::query()->lockForUpdate()->findOrFail($login);
                        if (Employee::withTrashed()->where('login_id', $user->id)->when($id, fn (Builder $query) => $query->whereKeyNot($id))->exists()) {
                            throw ValidationException::withMessages(['login_id' => 'This user is already linked to another employee.']);
                        }
                    }
                }
                $record->provisionLoginOnSave = false;
                $values = Arr::only($input, array_keys(EmployeeRegisterFields::fields('employee')));
                unset($values['provision_login']);
                $record->fill($values);
                if (filled($input['image_base64'] ?? null)) {
                    $bytes = base64_decode($input['image_base64'], true);
                    $image = $bytes === false ? false : @getimagesizefromstring($bytes);
                    if (! $image || strlen($bytes) > 1024 * 1024 || ! in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || max($image[0], $image[1]) > 4096) {
                        throw ValidationException::withMessages(['image_base64' => 'Choose a JPEG, PNG or WebP profile image up to 1 MB and 4096 pixels per side.']);
                    }
                    $stored = 'employee-photos/'.Str::uuid().'.'.match ($image['mime']) {
                        'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'webp'
                    };
                    abort_unless(Storage::put($stored, $bytes, 'private'), 500);
                    $record->profile_picture = $stored;
                } elseif ($input['remove_image'] ?? false) {
                    $record->profile_picture = null;
                }
                $this->logOverride($actor, $record, saving: true);
                $record->save();
                if ($input['provision_login'] && $record->login_id === null) {
                    abort_unless($actor->can('Create:User'), 403);
                    if (User::query()->where('email', $record->email)->exists()) {
                        throw ValidationException::withMessages(['login_id' => 'An account with this email exists. Select an available login explicitly.']);
                    }
                    $password = Str::random(16);
                    $user = User::query()->create(['name' => $record->full_name, 'email' => $record->email, 'password' => $password]);
                    $record->login_id = $user->id;
                    $record->saveQuietly();
                    $record->getConnection()->afterCommit(fn () => $user->notify(new UserAccountCreated($password)));
                }
                if ($record->explicit_reporting_manager_id !== null && app(EmployeeReportingService::class)->resolve($record)['manager'] === null) {
                    throw ValidationException::withMessages(['explicit_reporting_manager_id' => 'Choose an active employee without a circular reporting chain.']);
                }

                return $record->refresh();
            });
        } catch (Throwable $exception) {
            if ($stored !== null) {
                Storage::delete($stored);
            }
            if ($exception instanceof ValidationException) {
                throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => [str_starts_with($key, 'data.') ? substr($key, 5) : $key => $errors])->all());
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['employee_id' => 'The employee code, contact information or login is already in use.']);
            }
            throw $exception;
        }
    }

    private function validateChoices(User $actor, Employee $employee, string $section, array $input, Model $existing): void
    {
        foreach (EmployeeRegisterFields::fields($section) as $key => $field) {
            if ($field['type'] === 'picker' && isset($input[$key]) && (int) $input[$key] !== (int) $existing->{$key}
                && ! $this->catalog($actor, $field['kind'], $employee, $input['division_ou_id'] ?? null)->whereKey($input[$key])->exists()) {
                throw ValidationException::withMessages([$key => 'Choose an available option you can access.']);
            }
        }
    }

    public function sectionData(User $actor, int $id, string $section): array
    {
        $employee = $this->find($actor, $id);
        abort_unless($this->canReadSection($actor, $section), 403);
        $relation = self::SECTIONS[$section][0];
        $query = $employee->{$relation}();
        if ($section !== 'positions' && method_exists(new (self::SECTIONS[$section][1]), 'approval')) {
            $query->with('approval');
        }
        if ($section === 'positions') {
            $query->whereIn('positions.id', $this->catalog($actor, 'positions')->select('positions.id'));
        }
        $page = $query->orderBy($query->getRelated()->qualifyColumn('id'))->paginate(30);
        $shiftLabels = $section === 'shifts' ? $this->catalog($actor, 'shifts')->whereKey($page->getCollection()->pluck('shift_master_id'))->pluck('name', 'id')->all() : [];
        $page->through(function (Model $row) use ($actor, $employee, $section, $shiftLabels): array {
            $values = $section === 'positions' ? ['position_id' => $row->id, 'is_primary' => (bool) $row->pivot->is_primary] : $row->only(array_keys(EmployeeRegisterFields::fields($section)));
            foreach (EmployeeRegisterFields::fields($section) as $key => $field) {
                if ($field['type'] === 'date') {
                    $values[$key] = $row->{$key} ? substr((string) $row->getRawOriginal($key), 0, 10) : null;
                } elseif ($field['type'] === 'bool' && $section !== 'positions') {
                    $values[$key] = (bool) $row->{$key};
                }
            }
            if ($section === 'employment') {
                $values['org_unit_ids'] = $row->organizationalUnits()->pluck('organizational_units.id')->all();
            }

            return ['id' => $row->id, ...$values, 'labels' => match ($section) {
                'positions' => ['position_id' => [(string) $row->id => $row->name]], 'shifts' => ['shift_master_id' => array_intersect_key($shiftLabels, array_flip([$row->shift_master_id]))], default => $this->labels($actor, $employee, $section, $values)
            },
                'can_edit' => $this->canWriteSection($actor, $employee, $section, 'Update') && ($section === 'positions' || $this->unlocked($actor, $row)),
                'can_delete' => ! in_array($section, ['employment', 'statutory'], true) && $this->canWriteSection($actor, $employee, $section, $section === 'positions' ? 'Update' : 'Delete') && ($section === 'positions' || $this->unlocked($actor, $row))];
        });

        return $page->toArray() + ['fields' => $this->formFields($actor, $section), 'revision' => $this->revision($employee),
            'can_create' => $this->canWriteSection($actor, $employee, $section, $section === 'positions' ? 'Update' : 'Create') && (! in_array($section, ['employment', 'statutory'], true) || ! $employee->{$relation}()->exists()),
            'can_request_change' => $section === 'employment' && $this->canRequestChange($actor, $employee),
            'can_bulk_delete' => ! in_array($section, ['employment', 'statutory'], true) && $this->canWriteSection($actor, $employee, $section, $section === 'positions' ? 'Update' : 'Delete') && $actor->can('DeleteAny:'.class_basename(self::SECTIONS[$section][1])),
            'change_fields' => $section === 'employment' ? array_values(array_filter($this->formFields($actor, $section), fn (array $field): bool => array_key_exists($field['key'], HrChangeService::rules('employment')))) : []];
    }

    public function changeSection(User $actor, int $id, string $section, ?int $rowId, ?array $input, string $revision): ?Model
    {
        return (new Employee)->getConnection()->transaction(function () use ($actor, $id, $section, $rowId, $input, $revision): ?Model {
            $employee = $this->find($actor, $id, true);
            $ability = $section === 'positions' ? 'Update' : ($rowId === null ? 'Create' : ($input === null ? 'Delete' : 'Update'));
            abort_unless($this->canWriteSection($actor, $employee, $section, $ability), 403);
            $this->checkRevision($employee, $revision);
            $relation = $employee->{self::SECTIONS[$section][0]}();
            if ($section === 'positions') {
                $relation->whereIn('positions.id', $this->catalog($actor, 'positions')->select('positions.id'));
            }
            $row = $rowId === null ? null : $relation->lockForUpdate()->findOrFail($rowId);
            if ($section !== 'positions') {
                abort_unless($row === null || $this->unlocked($actor, $row), 409, 'This HR record is locked by approval.');
            }
            if ($input === null) {
                abort_if(in_array($section, ['employment', 'statutory'], true), 409, 'This employment record must be retained.');
                $section === 'positions' ? $relation->detach($rowId) : $row->delete();
                if ($section === 'positions') {
                    $this->normalizePrimary($employee);
                }
                $this->logOverride($actor, $employee);

                return null;
            }
            $input = Validator::make($input, EmployeeRegisterFields::rules($section))->validate();
            if ($section === 'positions') {
                $positionId = (int) $input['position_id'];
                abort_unless($this->catalog($actor, 'positions')->whereKey($positionId)->exists(), 403);
                abort_if($rowId !== null && $positionId !== $rowId, 409, 'Detach and assign another position explicitly.');
                $relation->syncWithoutDetaching([$positionId => ['is_primary' => (bool) $input['is_primary']]]);
                $this->normalizePrimary($employee, $input['is_primary'] ? $positionId : null);
                $this->logOverride($actor, $employee);

                return $relation->findOrFail($positionId);
            }
            if (in_array($section, ['employment', 'statutory'], true) && $row === null && $relation->exists()) {
                throw ValidationException::withMessages(['record' => 'This employee already has this record. Reload it.']);
            }
            $row ??= $relation->make();
            $this->validateChoices($actor, $employee, $section, $input, $row);
            if ($section === 'shifts') {
                $overlap = $relation->whereKeyNot($rowId ?? 0)->where('effective_from', '<=', $input['effective_to'] ?? '9999-12-31')
                    ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $input['effective_from']))->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['effective_from' => 'Shift dates overlap with an existing assignment.']);
                }
            }
            if ($section === 'week-offs' && $relation->whereKeyNot($rowId ?? 0)->where('day_of_week', $input['day_of_week'])->exists()) {
                throw ValidationException::withMessages(['day_of_week' => 'This employee already has a week-off entry for that day.']);
            }
            if ($section === 'statutory') {
                foreach (['pan', 'uan_no', 'pran', 'aadhar_number'] as $field) {
                    if (filled($input[$field] ?? null) && EmpStatutoryId::withTrashed()->whereKeyNot($rowId ?? 0)->where($field, $input[$field])->exists()) {
                        throw ValidationException::withMessages([$field => 'This statutory ID is already registered.']);
                    }
                }
            }
            $unitIds = array_map(intval(...), $input['org_unit_ids'] ?? []);
            $primaryUnitId = null;
            if ($section === 'employment') {
                $existing = $row->exists ? $row->organizationalUnits()->pluck('organizational_units.id')->all() : [];
                $divisionId = array_key_exists('division_ou_id', $input) ? $input['division_ou_id'] : $row->division_ou_id;
                $primaryUnitId = $row->exists ? $row->organizationalUnits()->wherePivot('is_primary', true)->first()?->id : null;
                if (! array_key_exists('org_unit_ids', $input)) {
                    $unitIds = $existing;
                }
                $visible = $this->catalog($actor, 'org-units', $employee, $divisionId)->pluck('id')->all();
                if (array_diff($unitIds, $existing, $visible) !== []) {
                    throw ValidationException::withMessages(['org_unit_ids' => 'Choose organizational units you can access.']);
                }
                $unitIds = array_unique([...$unitIds, ...array_diff($existing, $visible)]);
                OrganizationalUnitService::validateSelection($divisionId, $unitIds);
            }
            $this->logOverride($actor, $row, saving: true);
            $row->fill(Arr::except($input, ['org_unit_ids']))->save();
            if ($section === 'employment') {
                $primaryUnitId = in_array($primaryUnitId, $unitIds, true) ? $primaryUnitId : ($unitIds === [] ? null : reset($unitIds));
                $row->organizationalUnits()->sync(array_fill_keys($unitIds, ['is_primary' => false]));
                if ($primaryUnitId !== null) {
                    $row->organizationalUnits()->updateExistingPivot($primaryUnitId, ['is_primary' => true]);
                }
                app(EmployeeReportingService::class)->synchronize();
            }
            $this->logOverride($actor, $employee);

            return $row->refresh();
        }, 3);
    }

    private function normalizePrimary(Employee $employee, ?int $preferred = null): void
    {
        $positions = $employee->positions()->orderBy('positions.id')->get();
        $primary = $preferred ?? $positions->first(fn ($position): bool => (bool) $position->pivot->is_primary)?->id ?? $positions->first()?->id;
        foreach ($positions as $position) {
            $employee->positions()->updateExistingPivot($position->id, ['is_primary' => $position->id === $primary]);
        }
        app(EmployeeReportingService::class)->synchronize();
    }

    public function deleteRows(User $actor, int $id, string $section, array $ids, string $revision): void
    {
        (new Employee)->getConnection()->transaction(function () use ($actor, $id, $section, $ids, $revision): void {
            $employee = $this->find($actor, $id, true);
            $this->checkRevision($employee, $revision);
            abort_unless($actor->can('DeleteAny:'.class_basename(self::SECTIONS[$section][1])), 403);
            sort($ids);
            foreach ($ids as $rowId) {
                $this->changeSection($actor, $id, $section, $rowId, null, $this->revision($employee->fresh()));
            }
        }, 3);
    }

    public function canRequestChange(User $actor, Employee $employee): bool
    {
        return $this->canRead($actor) && ! $employee->trashed() && $employee->getApprovalStatus() !== 'pending'
            && $actor->can(['Update:Employee', 'ViewAny:HrChangeRequest', 'View:HrChangeRequest', 'Create:HrChangeRequest']);
    }

    public function requestChange(User $actor, int $id, string $type, array $input): HrChangeRequest
    {
        return (new Employee)->getConnection()->transaction(function () use ($actor, $id, $type, $input): HrChangeRequest {
            $employee = $this->find($actor, $id, true);
            abort_unless($this->canRequestChange($actor, $employee), 403);
            $this->checkRevision($employee, $input['revision']);
            $target = $type === 'employee' ? $employee : $employee->employmentDetail()->lockForUpdate()->firstOrFail();
            if ($type === 'employment') {
                abort_unless($this->canReadSection($actor, 'employment') && $actor->can('Update:EmploymentDetail') && $target->getApprovalStatus() !== 'pending', 403);
            }

            $values = Validator::make($input['values'], array_intersect_key(EmployeeRegisterFields::rules($type), $input['values']))->validate();

            return app(HrChangeService::class)->submit($type, $target->id, $values, $input['reason']);
        }, 3);
    }

    public function canAct(User $actor, Employee $employee, string $action): bool
    {
        return match ($action) {
            'restore' => $this->canRead($actor) && $employee->trashed() && $actor->can('Restore:Employee')
                && $employee->getApprovalStatus() !== 'pending' && $employee->approval_status !== 'pending'
                && (($employee->getApprovalStatus() !== 'approved' && $employee->approval_status !== 'approved') || $actor->can('OverrideApproval:Employee')),
            'submit' => $this->canChange($actor, $employee) && $employee->canStartApproval(),
            'delete' => $this->canChange($actor, $employee, 'Delete'),
            default => false,
        };
    }

    public function act(User $actor, array $ids, string $action, ?string $revision = null, bool $bulk = false): void
    {
        $this->authorize($actor);
        if ($bulk) {
            abort_unless($actor->can(($action === 'delete' ? 'DeleteAny' : ($action === 'restore' ? 'RestoreAny' : 'Update')).':Employee'), 403);
        }
        (new Employee)->getConnection()->transaction(function () use ($actor, $ids, $action, $revision): void {
            $records = $this->visible($actor)->whereKey($ids)->with('approval')->orderBy('id')->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $record) {
                if ($revision !== null) {
                    $this->checkRevision($record, $revision);
                }
                abort_unless($this->canAct($actor, $record, $action), 403);
                if ($action === 'delete') {
                    abort_if($record->login_id !== null || $this->hasHistory($record), 409, 'Employees with linked logins or HR history must be retained.');
                    foreach (self::SECTIONS as $section => [$relation]) {
                        $linked = $record->{$relation}();
                        if ($section !== 'positions') {
                            $linked->withTrashed();
                        }
                        abort_if($linked->exists(), 409, 'Remove linked HR records before deleting the employee.');
                    }
                }
            }
            foreach ($records as $record) {
                $this->logOverride($actor, $record, saving: true);
                $record->provisionLoginOnSave = false;
                try {
                    match ($action) {
                        'submit' => $record->startApprovalFromRules(), 'restore' => $record->restore(), default => $record->delete()
                    };
                } catch (LogicException $exception) {
                    throw ValidationException::withMessages(['approval' => $exception->getMessage()]);
                }
            }
        }, 3);
    }

    private function hasHistory(Employee $employee): bool
    {
        foreach ([DailyAttendance::class, EmployeeAttendance::class, LeaveApplication::class, HrChangeRequest::class, EmpSalary::class, PayrollLeaveSnapshot::class] as $class) {
            if ($class::withTrashed()->where('employee_id', $employee->id)->exists()) {
                return true;
            }
        }

        return Employee::withTrashed()->where('explicit_reporting_manager_id', $employee->id)->exists() || $employee->departments()->withTrashed()->exists();
    }

    private function logOverride(User $actor, Model $record, bool $saving = false): void
    {
        if ($saving && $record->getRawOriginal('approval_status') === 'approved') {
            return;
        }
        if (($record->approval_status === 'approved' || (method_exists($record, 'getApprovalStatus') && $record->getApprovalStatus() === 'approved')) && $actor->can('OverrideApproval:'.class_basename($record))) {
            ApprovalOverride::query()->create(['model_type' => $record::class, 'model_id' => $record->id, 'user_id' => $actor->id, 'reason' => 'Authorized employee maintenance']);
        }
    }

    private function checkRevision(Employee $employee, string $revision): void
    {
        abort_unless(hash_equals($this->revision($employee), $revision), 409, 'The employee changed. Reload before continuing.');
    }
}
