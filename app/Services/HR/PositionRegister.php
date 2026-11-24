<?php

namespace App\Services\HR;

use App\Http\Requests\Api\V1\PositionRegisterRequest;
use App\Models\ApprovalOverride;
use App\Models\EmpDepartment;
use App\Models\EmpGrade;
use App\Models\EmpJobTitle;
use App\Models\Employee;
use App\Models\ExpenseConfiguration;
use App\Models\JobRole;
use App\Models\LocationMaster;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Territory;
use App\Models\User;
use App\Services\EmployeeReportingService;
use App\Services\OrganizationalUnitService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class PositionRegister
{
    public const FIELDS = ['name', 'code', 'status', 'level', 'description', 'job_role_id', 'reports_to_position_id', 'hq_territory_id', 'location_id', 'division_ou_id', 'department_id', 'job_title_id', 'job_grade_id'];

    public const OPTIONS = [
        'roles' => [JobRole::class, 'name'], 'parents' => [Position::class, 'name'], 'territories' => [Territory::class, 'name'], 'locations' => [LocationMaster::class, 'name'],
        'divisions' => [OrganizationalUnit::class, 'name'], 'org-units' => [OrganizationalUnit::class, 'name'], 'departments' => [EmpDepartment::class, 'department_name'],
        'titles' => [EmpJobTitle::class, 'title'], 'grades' => [EmpGrade::class, 'grade_name'], 'employees' => [Employee::class, 'first_name'],
    ];

    public const SELECTORS = ['job_role_id' => 'roles', 'reports_to_position_id' => 'parents', 'territory_ids' => 'territories', 'hq_territory_id' => 'territories', 'location_id' => 'locations', 'division_ou_id' => 'divisions', 'org_unit_ids' => 'org-units', 'department_id' => 'departments', 'job_title_id' => 'titles', 'job_grade_id' => 'grades', 'employee_ids' => 'employees'];

    public const RELATIONS = ['territory_ids' => ['territories', 'territories'], 'org_unit_ids' => ['organizationalUnits', 'organizational_units'], 'employee_ids' => ['employees', 'employees']];

    public function canRead(User $actor): bool
    {
        return $actor->can(['ViewAny:Position', 'View:Position']);
    }

    public function authorize(User $actor): void
    {
        abort_unless($this->canRead($actor), 403);
    }

    public function visible(User $actor): Builder
    {
        return Position::query()->applyVisibility('Position', $actor)->when(! $this->canRead($actor), fn (Builder $query) => $query->whereKey([]));
    }

    public function find(User $actor, int $id, bool $lock = false): Position
    {
        $this->authorize($actor);

        return $this->visible($actor)->with('approval')->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function canChange(User $actor, Position $record, string $permission = 'Update'): bool
    {
        $states = [$this->approvalStatus($record), $record->getApprovalStatus()];

        return $this->canRead($actor) && ! $record->trashed() && $actor->can($permission.':Position') && ! in_array('pending', $states, true)
            && (! in_array('approved', $states, true) || $actor->can('OverrideApproval:Position'));
    }

    private function approvalStatus(Position $record): ?string
    {
        return $record->getApprovalStatus() ?? ($record->approval_status === 'pending' ? 'draft' : $record->approval_status);
    }

    public function catalog(User $actor, string $kind, ?int $id = null, ?int $division = null): Builder
    {
        [$class] = self::OPTIONS[$kind];
        $query = $class::query()->applyVisibility(class_basename($class), $actor);
        if (in_array($kind, ['parents', 'territories', 'locations', 'employees'], true)) {
            $query->when(! $actor->can(['ViewAny:'.class_basename($class), 'View:'.class_basename($class)]), fn (Builder $query) => $query->whereKey([]));
        }
        if ($kind === 'divisions') {
            $query->whereHas('typeMaster', fn (Builder $query) => $query->where('name', 'Division'));
        } elseif ($kind === 'org-units' && $division !== null) {
            $query->whereIn('id', OrganizationalUnitService::divisionUnitIds($division));
        } elseif ($kind === 'parents' && $id !== null) {
            $graph = Position::withTrashed()->pluck('reports_to_position_id', 'id')->all();
            $query->whereKeyNot(array_values(array_filter(array_keys($graph), fn (int $candidate): bool => $this->wouldCycle($graph, $candidate, $id))));
        }

        return $query;
    }

    private function wouldCycle(array $graph, ?int $parent, int $id): bool
    {
        $seen = [$id => true];
        while ($parent !== null) {
            if (isset($seen[$parent])) {
                return true;
            }
            $seen[$parent] = true;
            $parent = isset($graph[$parent]) ? (int) $graph[$parent] : null;
        }

        return false;
    }

    public function options(User $actor, string $kind, array $input): array
    {
        $this->authorize($actor);
        if (isset($input['position_id'])) {
            $this->find($actor, $input['position_id']);
        }
        $column = self::OPTIONS[$kind][1];
        $query = $this->catalog($actor, $kind, $input['position_id'] ?? null, $input['division_ou_id'] ?? null);
        $search = trim($input['search'] ?? '');
        $query->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($kind, $column, $search): void {
            $query->whereLike($column, '%'.$search.'%');
            if ($kind === 'employees') {
                $query->orWhereLike('employee_id', '%'.$search.'%')->orWhereLike('last_name', '%'.$search.'%');
            } elseif ($kind === 'parents') {
                $query->orWhereLike('code', '%'.$search.'%');
            }
        }));
        $page = $query->orderBy($column)->orderBy('id')->paginate(30);

        return ['data' => $page->getCollection()->map(fn ($row): array => ['id' => $row->id, 'label' => $kind === 'employees' ? $row->employee_id.' - '.$row->full_name : $row->{$column}])->all(), 'meta' => ['last_page' => $page->lastPage()]];
    }

    public function fields(): array
    {
        $fields = [];
        foreach (['name' => 'Name', 'code' => 'Code', 'status' => 'Status', 'level' => 'Level', 'description' => 'Description', 'job_role_id' => 'Job Role', 'reports_to_position_id' => 'Reports To Position', 'territory_ids' => 'Associated Territories', 'hq_territory_id' => 'HQ Territory', 'location_id' => 'Location', 'division_ou_id' => 'Division', 'org_unit_ids' => 'Organizational Units', 'department_id' => 'Department', 'job_title_id' => 'Job Title', 'job_grade_id' => 'Job Grade', 'employee_ids' => 'Assigned Employees'] as $key => $label) {
            $fields[] = ['key' => $key, 'label' => $label, 'type' => isset(self::SELECTORS[$key]) ? (isset(self::RELATIONS[$key]) ? 'multi' : 'picker') : ($key === 'status' ? 'select' : 'text'), 'required' => in_array($key, ['name', 'code', 'status', 'job_role_id', 'territory_ids', 'hq_territory_id'], true), 'max' => $key === 'description' ? 65535 : 255,
                ...($key === 'status' ? ['options' => ['active' => 'Active', 'inactive' => 'Inactive', 'vacant' => 'Vacant']] : []), ...(isset(self::SELECTORS[$key]) ? ['kind' => self::SELECTORS[$key]] : [])];
        }

        return $fields;
    }

    public function listQuery(User $actor, array $input): Builder
    {
        $this->authorize($actor);
        $query = $this->visible($actor)->with('approval');
        $search = trim($input['search'] ?? '');
        $query->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereLike('name', '%'.$search.'%')->orWhereLike('code', '%'.$search.'%')->orWhereLike('level', '%'.$search.'%')));
        foreach (['status', 'location_id', 'reports_to_position_id', 'division_ou_id', 'department_id', 'job_title_id', 'job_grade_id', 'job_role_id'] as $field) {
            $query->when(filled($input[$field] ?? null), fn (Builder $query) => $query->where($field, $input[$field]));
        }
        foreach (self::RELATIONS as $field => [$relation]) {
            if ($field !== 'employee_ids') {
                $query->when(filled($input[$field] ?? null), fn (Builder $query) => $query->whereHas($relation, fn (Builder $query) => $query->whereKey($input[$field])));
            }
        }

        return $query;
    }

    public function revision(Position $record): string
    {
        $relations = [];
        foreach (self::RELATIONS as $field => [$relation, $table]) {
            $relations[$field] = $record->{$relation}()->withTrashed()->orderBy($table.'.id')->get()->map(fn ($row): array => [$row->id, $row->pivot->getAttributes()])->all();
        }

        return hash('sha256', json_encode(Arr::sortRecursive([$record->getAttributes(), $record->approval()->first()?->getAttributes(), $relations]), JSON_THROW_ON_ERROR));
    }

    public function summary(User $actor, Position $record): array
    {
        return [...$record->only(['id', 'name', 'code', 'level']), 'status' => $record->status->value, 'approval_status' => $this->approvalStatus($record),
            'actions' => array_values(array_filter(['submit', 'delete'], fn (string $action): bool => $this->canAct($actor, $record, $action)))];
    }

    public function detail(User $actor, Position $record): array
    {
        $data = [...$this->summary($actor, $record), ...$record->only(self::FIELDS), 'status' => $record->status->value, 'revision' => $this->revision($record), 'can_edit' => $this->canChange($actor, $record), 'can_assign_employees' => $actor->can(['ViewAny:Employee', 'View:Employee', 'Update:Employee']), 'fields' => $this->fields()];
        foreach (self::RELATIONS as $field => [$relation, $table]) {
            $data[$field] = $record->{$relation}()->withTrashed()->pluck($table.'.id')->all();
        }
        foreach (self::SELECTORS as $field => $kind) {
            $column = self::OPTIONS[$kind][1];
            $data['labels'][$field] = $this->catalog($actor, $kind)->whereKey(Arr::wrap($data[$field] ?? []))->get()->mapWithKeys(fn ($row): array => [$row->id => $kind === 'employees' ? $row->employee_id.' - '.$row->full_name : $row->{$column}])->all();
        }

        return $data;
    }

    public function save(User $actor, ?int $id, array $input): Position
    {
        $this->authorize($actor);
        $input = Validator::make($input, PositionRegisterRequest::saveRules($id !== null))->validate();

        return (new Position)->getConnection()->transaction(function () use ($actor, $id, $input): Position {
            Position::withTrashed()->orderBy('id')->lockForUpdate()->get(['id']);
            $record = $id === null ? new Position : $this->find($actor, $id, true);
            abort_unless($id === null ? $actor->can('Create:Position') : $this->canChange($actor, $record), 403);
            if ($id !== null) {
                $this->checkRevision($record, $input['revision']);
            }
            if (Position::withTrashed()->where('code', $input['code'])->when($id !== null, fn (Builder $query) => $query->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages(['code' => 'This position code is already in use.']);
            }
            foreach (self::SELECTORS as $field => $kind) {
                if (isset(self::RELATIONS[$field]) || $field === 'hq_territory_id') {
                    continue;
                }
                $value = $input[$field] ?? null;
                if ($value !== null && (int) $value !== (int) $record->{$field} && ! $this->catalog($actor, $kind, $id)->whereKey($value)->exists()) {
                    throw ValidationException::withMessages([$field => 'Choose a selection you can access.']);
                }
            }
            if ($this->wouldCycle(Position::withTrashed()->pluck('reports_to_position_id', 'id')->all(), $input['reports_to_position_id'] ?? null, $id ?? 0)) {
                throw ValidationException::withMessages(['reports_to_position_id' => 'The reporting position would create a cycle.']);
            }
            $relations = [];
            foreach (self::RELATIONS as $field => [$relation, $table]) {
                $existing = $record->exists ? $record->{$relation}()->withTrashed()->pluck($table.'.id')->all() : [];
                $ids = array_map(intval(...), $input[$field] ?? []);
                $visible = $this->catalog($actor, self::SELECTORS[$field])->pluck('id')->all();
                if (array_diff($ids, $existing, $visible) !== []) {
                    throw ValidationException::withMessages([$field => 'Choose selections you can access.']);
                }
                $relations[$relation] = array_values(array_unique([...$ids, ...array_diff($existing, $visible)]));
                if ($field === 'employee_ids') {
                    $changed = array_values(array_unique([...array_diff($existing, $relations[$relation]), ...array_diff($relations[$relation], $existing)]));
                    if ($changed !== []) {
                        abort_unless($actor->can('Update:Employee'), 403);
                        foreach (Employee::query()->whereKey($changed)->with('approval')->orderBy('id')->lockForUpdate()->get() as $employee) {
                            abort_unless(app(EmployeeRegister::class)->canChange($actor, $employee), 403, 'Assigned employees must be editable.');
                            if ($employee->approval_status === 'approved' || $employee->getApprovalStatus() === 'approved') {
                                ApprovalOverride::query()->create(['model_type' => Employee::class, 'model_id' => $employee->id, 'user_id' => $actor->id, 'reason' => 'Authorized position assignment maintenance']);
                            }
                        }
                    }
                }
            }
            if (! in_array((int) $input['hq_territory_id'], $relations['territories'], true)) {
                throw ValidationException::withMessages(['hq_territory_id' => 'Choose HQ from the associated territories.']);
            }
            if ((int) $input['hq_territory_id'] !== (int) $record->hq_territory_id && ! $this->catalog($actor, 'territories')->whereKey($input['hq_territory_id'])->exists()) {
                throw ValidationException::withMessages(['hq_territory_id' => 'Choose an HQ territory you can access.']);
            }
            try {
                OrganizationalUnitService::validateSelection($input['division_ou_id'] ?? null, $relations['organizationalUnits']);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['org_unit_ids' => 'Choose organizational units within the selected Division.']);
            }
            $record->fill(Arr::only($input, self::FIELDS) + array_fill_keys(array_diff(self::FIELDS, ['name', 'code', 'status', 'job_role_id', 'hq_territory_id']), null));
            if (! $record->exists || ($record->approval_status === 'pending' && $record->approval === null)) {
                $record->approval_status = 'draft';
            }
            $record->is_multi_territory = count($relations['territories']) > 1;
            if (($record->getRawOriginal('approval_status') !== 'approved' || ! $record->isDirty()) && ($record->approval_status === 'approved' || $record->getApprovalStatus() === 'approved')) {
                $this->logOverride($actor, $record);
            }
            $record->save();
            foreach (self::RELATIONS as [$relation]) {
                $record->{$relation}()->sync($relations[$relation]);
            }
            app(EmployeeReportingService::class)->synchronize();

            return $record->refresh();
        }, 3);
    }

    public function checkRevision(Position $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The position changed. Reload before continuing.');
    }

    public function canAct(User $actor, Position $record, string $action): bool
    {
        return match ($action) {
            'submit' => $this->canChange($actor, $record) && $record->canStartApproval(), 'delete' => $this->canChange($actor, $record, 'Delete'), default => false,
        };
    }

    public function act(User $actor, array $ids, string $action, ?string $revision = null, bool $bulk = false): void
    {
        $this->authorize($actor);
        if ($bulk) {
            abort_unless($actor->can(($action === 'delete' ? 'DeleteAny' : 'Update').':Position'), 403);
        }
        (new Position)->getConnection()->transaction(function () use ($actor, $ids, $action, $revision): void {
            Position::withTrashed()->orderBy('id')->lockForUpdate()->get(['id']);
            $records = $this->visible($actor)->with('approval')->whereKey($ids)->orderBy('id')->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $record) {
                if ($revision !== null) {
                    $this->checkRevision($record, $revision);
                }
                abort_unless($this->canAct($actor, $record, $action), 403);
                if ($action === 'delete') {
                    abort_if($record->employees()->withTrashed()->exists() || $record->subordinates()->withTrashed()->exists()
                        || Territory::withTrashed()->where('reporting_position_id', $record->id)->exists()
                        || ExpenseConfiguration::withTrashed()->whereHas('positions', fn (Builder $query) => $query->whereKey($record->id))->exists(), 409, 'Positions with employee assignments, reporting dependants or expense configuration must be retained.');
                }
            }
            foreach ($records as $record) {
                try {
                    if ($action === 'submit') {
                        $record->startApprovalFromRules();
                    } else {
                        if ($record->approval_status !== 'approved' && $record->getApprovalStatus() === 'approved') {
                            $this->logOverride($actor, $record);
                        }
                        $record->delete();
                    }
                } catch (LogicException $exception) {
                    throw ValidationException::withMessages(['approval' => $exception->getMessage()]);
                }
            }
        }, 3);
    }

    private function logOverride(User $actor, Position $record): void
    {
        ApprovalOverride::query()->create(['model_type' => Position::class, 'model_id' => $record->id, 'user_id' => $actor->id, 'reason' => 'Authorized position maintenance']);
    }
}
