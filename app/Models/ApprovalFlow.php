<?php

namespace App\Models;

use App\Services\Approval\ApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ApprovalFlow extends BaseModel
{
    protected $fillable = [
        'module',
        'name',
        'route_type',
        'priority',
        'version',
        'condition_type',
        'effective_from',
        'effective_to',
        'territory_id',
        'min_amount',
        'max_amount',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'version' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalFlowStep::class)
            ->orderBy('step_order');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function scopeEffectiveOn(Builder $query, mixed $date = null): Builder
    {
        $date ??= today();

        return $query
            ->where(fn (Builder $query): Builder => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn (Builder $query): Builder => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query->where('module', $module)
            ->orWhereIn('id', DB::table('approval_flow_modules')->select('approval_flow_id')->where('module', $module)));
    }

    public function scopeMatching(Builder $query, string $module, ?int $territoryId, ?float $amount, ?string $date = null): Builder
    {
        return $query->forModule($module)->where('active', true)->effectiveOn($date)
            ->where(fn (Builder $query): Builder => $query->whereNull('territory_id')->orWhere('territory_id', $territoryId))
            ->where(fn (Builder $query): Builder => $query->where('condition_type', 'unconditional')
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where(fn (Builder $query): Builder => $query->whereNull('min_amount')->when($amount !== null, fn (Builder $query): Builder => $query->orWhere('min_amount', '<=', $amount)))
                    ->where(fn (Builder $query): Builder => $query->whereNull('max_amount')->when($amount !== null, fn (Builder $query): Builder => $query->orWhere('max_amount', '>=', $amount)))));
    }

    public function getModulesAttribute(): array
    {
        return array_values(array_unique([$this->module, ...DB::table('approval_flow_modules')
            ->where('approval_flow_id', $this->id)->orderBy('module')->pluck('module')->all()]));
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?? str($this->module)->headline()->toString();
    }

    public function getRouteSummaryAttribute(): string
    {
        $labels = ApprovalFlowStep::routingOptions();

        return $this->steps->map(fn (ApprovalFlowStep $step): string => $labels[$step->routing_type] ?? 'Unknown step')->implode(' → ');
    }

    public function saveConfiguration(array $data): self
    {
        return DB::transaction(function () use ($data): self {
            ApprovalSetting::query()->orderBy('id')->lockForUpdate()->get();
            $flow = $this->exists ? static::query()->lockForUpdate()->findOrFail($this->id) : $this;
            $modules = array_values(array_unique($data['modules'] ?? []));
            sort($modules);
            if ($modules === [] || array_diff($modules, array_keys(ApprovalSetting::approvedModuleOptions()))) {
                throw ValidationException::withMessages(['data.modules' => 'Select at least one enabled approval module.']);
            }

            $type = $data['route_type'] ?? 'reporting_manager';
            $levels = $type === 'reporting_manager' ? [['routing_type' => $type]] : array_values($data['levels'] ?? []);
            if (! in_array($type, ['reporting_manager', 'custom'], true) || $levels === []) {
                throw ValidationException::withMessages(['data.levels' => 'Add at least one approval level.']);
            }
            $steps = [];
            foreach ($levels as $index => $level) {
                $routing = $level['routing_type'] ?? '';
                $role = $routing === 'job_role' ? ($level['job_role_id'] ?? null) : null;
                $employeeId = in_array($routing, ['job_role', 'specific_employee'], true) ? ($level['approver_employee_id'] ?? null) : null;
                $scope = $role ? ($level['territory_scope'] ?? 'all') : 'all';
                $employee = $employeeId ? Employee::query()->with('user')->find($employeeId) : null;
                if (! array_key_exists($routing, ApprovalFlowStep::routingOptions())
                    || ($index === 0 && $routing === 'previous_manager')
                    || ($routing === 'job_role' && (! $role || ! JobRole::query()->whereKey($role)->exists()))
                    || ($routing === 'specific_employee' && ! $employeeId)
                    || ($employeeId && (! $employee?->is_active || ! $employee->user))
                    || ! in_array($scope, ['all', 'self', 'children'], true)
                    || ($scope !== 'all' && empty($data['territory_id']))) {
                    throw ValidationException::withMessages(['data.levels' => 'Check the step type, employee login, job role and territory for level '.($index + 1).'.']);
                }
                if ($employee && $role && ! app(ApprovalService::class)
                    ->approverCandidates((int) $role, empty($data['territory_id']) ? null : (int) $data['territory_id'], $scope)
                    ->contains('id', $employee->login_id)) {
                    throw ValidationException::withMessages(['data.levels' => 'The selected employee must match the role and territory for level '.($index + 1).'.']);
                }
                $steps[] = ['routing_type' => $routing, 'job_role_id' => $role, 'approver_employee_id' => $employeeId,
                    'approver_user_id' => null, 'territory_scope' => $scope, 'can_skip' => $routing === 'job_role' && (bool) ($data['allow_optional_role_levels'] ?? false) && (bool) ($level['can_skip'] ?? false), 'sla_hours' => (int) ($data['deadline_hours'] ?? 24)];
            }

            $previousModules = $flow->exists ? $flow->modules : [];
            sort($previousModules);
            $existing = $flow->exists ? $flow->steps()->get() : collect();
            $flow->fill(array_intersect_key($data, array_flip(['name', 'condition_type', 'territory_id', 'min_amount', 'max_amount', 'effective_from', 'effective_to'])));
            $flow->condition_type ??= 'unconditional';
            $flow->module = $modules[0];
            $flow->route_type = $type;
            if ($flow->condition_type === 'unconditional') {
                $flow->min_amount = $flow->max_amount = null;
            }
            if (($flow->min_amount !== null && $flow->max_amount !== null && $flow->min_amount > $flow->max_amount)
                || ($flow->effective_from && $flow->effective_to && $flow->effective_from->gt($flow->effective_to))) {
                throw ValidationException::withMessages(['data.condition_type' => 'Check the amount and effective date ranges.']);
            }
            $changed = $previousModules !== $modules
                || $flow->isDirty(['route_type', 'condition_type', 'territory_id', 'min_amount', 'max_amount', 'effective_from', 'effective_to'])
                || $existing->count() !== count($steps)
                || $existing->values()->contains(fn (ApprovalFlowStep $step, int $index): bool => isset($steps[$index]) && $step->fill($steps[$index])->isDirty());

            $flow->active = false;
            $flow->version = $flow->exists ? $flow->getOriginal('version') + (int) $changed : 1;
            $flow->save();
            DB::table('approval_flow_modules')->where('approval_flow_id', $flow->id)->delete();
            DB::table('approval_flow_modules')->insert(array_map(fn (string $module): array => ['approval_flow_id' => $flow->id, 'module' => $module], $modules));

            foreach ($steps as $index => $step) {
                $record = $flow->steps()->withTrashed()->firstOrNew(['step_order' => $index + 1]);
                $record->fill($step)->save();
                if ($record->trashed()) {
                    $record->restore();
                }
            }
            $flow->steps()->where('step_order', '>', count($steps))->delete();
            try {
                $flow->update(['active' => (bool) ($data['active'] ?? false)]);
            } catch (LogicException $exception) {
                throw ValidationException::withMessages(['data.modules' => $exception->getMessage()]);
            }

            return $flow->refresh();
        });
    }

    protected static function booted(): void
    {
        static::restoring(fn (ApprovalFlow $flow) => $flow->active = false);
        static::saving(function (ApprovalFlow $flow): void {
            if ($flow->condition_type === 'unconditional') {
                $flow->min_amount = $flow->max_amount = null;
            }
            if (! $flow->active) {
                return;
            }

            foreach ($flow->exists ? $flow->modules : [$flow->module] as $module) {
                $overlap = static::query()->forModule($module)
                    ->when($flow->exists, fn (Builder $query): Builder => $query->whereKeyNot($flow->id))
                    ->where('active', true)
                    ->when($flow->territory_id !== null, fn (Builder $query): Builder => $query
                        ->where(fn (Builder $query): Builder => $query->whereNull('territory_id')->orWhere('territory_id', $flow->territory_id)))
                    ->when($flow->effective_to, fn (Builder $query): Builder => $query
                        ->where(fn (Builder $query): Builder => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $flow->effective_to)))
                    ->when($flow->effective_from, fn (Builder $query): Builder => $query
                        ->where(fn (Builder $query): Builder => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $flow->effective_from)))
                    ->where(fn (Builder $query): Builder => $query->where('condition_type', 'unconditional')
                        ->orWhere(fn (Builder $query): Builder => $query
                            ->when($flow->max_amount !== null, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('min_amount')->orWhere('min_amount', '<=', $flow->max_amount)))
                            ->when($flow->min_amount !== null, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('max_amount')->orWhere('max_amount', '>=', $flow->min_amount)))))
                    ->exists();
                if ($overlap) {
                    throw new LogicException("An active approval flow already overlaps {$module} for this territory, amount and effective period.");
                }
            }
        });
    }
}
