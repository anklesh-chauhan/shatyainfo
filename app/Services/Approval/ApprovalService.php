<?php

namespace App\Services\Approval;

use App\Enums\ApprovalActivityAction;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepStatus;
use App\Events\ApprovalStatusChanged;
use App\Models\Approval;
use App\Models\ApprovalActivity;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\Territory;
use App\Models\User;
use App\Services\EmployeeReportingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

class ApprovalService
{
    /**
     * Start approval for any approvable model.
     *
     * @throws LogicException
     */
    public function start(
        Model $approvable,
        string $module,
        ?int $territoryId = null,
        ?float $amount = null
    ): Approval {
        if (Auth::id() === null) {
            throw new LogicException('An authenticated requester is required to start approval.');
        }

        if (! ApprovalSetting::moduleRequiresApproval($module)) {
            throw new LogicException("Approval is not enabled for module [{$module}].");
        }

        if (! ApprovalSetting::supportsModule($module) || ! array_key_exists($approvable::class, ApprovalSetting::eligibleModuleOptions()) || class_basename($approvable) !== class_basename($module)) {
            throw new LogicException("Unsupported approval module or record mismatch [{$module}].");
        }

        $module = class_basename($module);

        return DB::transaction(function () use ($approvable, $module, $territoryId, $amount): Approval {
            $approvable = $approvable->newQuery()
                ->whereKey($approvable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($approvable->approval()->whereIn('approval_status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])->exists()) {
                throw new LogicException('Approval already exists for this record.');
            }

            if (method_exists($approvable, 'prepareOperationalApproval')) {
                $approvable->prepareOperationalApproval();
                $amount = $approvable->approvalAmount();
            }
            $amount ??= $this->resolveAmount($approvable);
            $subject = $this->requestEmployee($approvable, Auth::user());
            $route = $this->resolveRoute($subject, $module, $territoryId, $amount, requesterId: Auth::id());
            $flow = $route['flow'];

            $approval = $approvable->approval()->create([
                'approval_flow_id' => $flow->id,
                'module' => $module,
                'record_type' => $approvable::class,
                'record_id' => $approvable->getKey(),
                'requested_by' => Auth::id(),
                'requested_amount' => $amount,
                'territory_id' => $territoryId,
                'flow_version' => $flow->version,
                'approval_status' => ApprovalStatus::Pending->value,
                'submitted_record_summary' => [...$this->submittedRecordSummary($approvable), 'flow_name' => $flow->display_name, 'employee_id' => $subject?->id],
            ]);

            $selectedSteps = [];
            $selectedApprovers = [];

            foreach ($route['steps'] as $resolved) {
                ['step' => $flowStep, 'original' => $user, 'assigned' => $assignedUser, 'reason' => $reason] = $resolved;
                if (! $assignedUser) {
                    $this->logActivity($approval, ApprovalActivityAction::Skipped, metadata: [
                        'flow_step_id' => $flowStep->id,
                        'reason' => $reason,
                    ]);

                    continue;
                }

                $step = ApprovalStep::create([
                    'approval_id' => $approval->id,
                    'step_order' => $flowStep->step_order,
                    'job_role_id' => $flowStep->job_role_id,
                    'assigned_user_id' => $assignedUser->id,
                    'reassigned_from_user_id' => $assignedUser->is($user) ? null : $user->id,
                    'status' => ApprovalStepStatus::Pending->value,
                    'due_at' => now()->addHours($flowStep->sla_hours ?? 24),
                ]);

                $selectedSteps[] = [
                    'approval_flow_step_id' => $flowStep->id,
                    'approval_step_id' => $step->id,
                    'step_order' => $flowStep->step_order,
                    'job_role_id' => $flowStep->job_role_id,
                    'routing_type' => $flowStep->routing_type,
                    'reason' => $reason,
                    'approver_employee_id' => $flowStep->approver_employee_id,
                    'territory_scope' => $flowStep->territory_scope,
                    'can_skip' => (bool) $flowStep->can_skip,
                    'sla_hours' => $flowStep->sla_hours,
                ];

                $selectedApprovers[] = [
                    'approval_step_id' => $step->id,
                    'step_order' => $step->step_order,
                    'user_id' => $assignedUser->id,
                    'name' => $assignedUser->name,
                    'original_name' => $user->name,
                    'reason' => $reason,
                    'delegated_from_user_id' => $assignedUser->is($user) ? null : $user->id,
                ];

                $this->logActivity($approval, ApprovalActivityAction::Assigned, $step);
            }

            $approval->update([
                'selected_steps' => $selectedSteps,
                'selected_approvers' => $selectedApprovers,
            ]);

            $this->logActivity($approval, ApprovalActivityAction::Submitted);

            if ($approval->steps()->count() === 0) {
                throw new LogicException('No actionable approval steps remain. Configure at least one available approver; automatic approval is not permitted.');
            }

            if (method_exists($approvable, 'markOperationalApprovalSubmitted')) {
                $approvable->markOperationalApprovalSubmitted();
            }

            return $approval->refresh();
        });
    }

    public function approve(Approval $approval, int $userId, ?string $comments = null): bool
    {
        return DB::transaction(function () use ($approval, $userId, $comments): bool {
            $approval = Approval::query()
                ->whereKey($approval->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($approval->isFinalized() || $approval->approval_status !== ApprovalStatus::Pending->value) {
                return false;
            }

            $step = $this->currentStepForUpdate($approval);

            if (! $step || $step->assigned_user_id !== $userId) {
                return false;
            }

            $fromStatus = $step->status->value;
            $step->transitionTo(ApprovalStepStatus::Approved, $comments);

            $this->logActivity($approval, ApprovalActivityAction::Approved, $step, $userId, $fromStatus, ApprovalStepStatus::Approved->value, $comments);

            if ($approval->fresh()->isFullyApproved()) {
                $this->finalizeApproval($approval, ApprovalStatus::Approved);
            }

            return true;
        });
    }

    public function reject(Approval $approval, int $userId, ?string $comments = null): bool
    {
        return DB::transaction(function () use ($approval, $userId, $comments): bool {
            $approval = Approval::query()
                ->whereKey($approval->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($approval->isFinalized() || $approval->approval_status !== ApprovalStatus::Pending->value) {
                return false;
            }

            $step = $this->currentStepForUpdate($approval);

            if (! $step || $step->assigned_user_id !== $userId) {
                return false;
            }

            $fromStatus = $step->status->value;
            $step->transitionTo(ApprovalStepStatus::Rejected, $comments);

            $this->logActivity($approval, ApprovalActivityAction::Rejected, $step, $userId, $fromStatus, ApprovalStepStatus::Rejected->value, $comments);
            $this->finalizeApproval($approval, ApprovalStatus::Rejected);

            return true;
        });
    }

    public function cancel(Approval $approval): void
    {
        DB::transaction(function () use ($approval): void {
            $approval = Approval::query()
                ->whereKey($approval->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($approval->isFinalized()) {
                return;
            }

            $approval->steps()
                ->where('status', ApprovalStepStatus::Pending->value)
                ->each(function (ApprovalStep $step) use ($approval): void {
                    $fromStatus = $step->status->value;
                    $step->transitionTo(ApprovalStepStatus::Cancelled);
                    $this->logActivity($approval, ApprovalActivityAction::Cancelled, $step, Auth::id(), $fromStatus, ApprovalStepStatus::Cancelled->value);
                });

            $this->finalizeApproval($approval, ApprovalStatus::Cancelled);
        });
    }

    protected function currentStepForUpdate(Approval $approval): ?ApprovalStep
    {
        return ApprovalStep::query()
            ->where('approval_id', $approval->id)
            ->where('status', ApprovalStepStatus::Pending->value)
            ->orderBy('step_order')
            ->lockForUpdate()
            ->first();
    }

    protected function finalizeApproval(Approval $approval, ApprovalStatus $status): void
    {
        $updated = Approval::query()
            ->whereKey($approval->id)
            ->whereNull('finalized_at')
            ->update([
                'approval_status' => $status->value,
                'completed_at' => now(),
                'finalized_at' => now(),
            ]);

        if ($updated !== 1) {
            return;
        }

        $approval->refresh();

        if (in_array($approval->record_type, [...OperationalApprovalHandler::supports(), ...HrApprovalHandler::supports(), LeaveApplication::class], true)) {
            app(ApprovalDomainDispatcher::class)->dispatch($approval, $status->value);
        }

        DB::afterCommit(function () use ($approval, $status): void {
            event(new ApprovalStatusChanged($approval, $status->value));
        });
    }

    private function requestEmployee(Model $record, User $requester): ?Employee
    {
        return match (class_basename($record)) {
            'LeaveApplication', 'LeaveAdjustment', 'LeaveEncashment', 'LeaveCancellation', 'HrChangeRequest' => $record->employee,
            'SalesDcr', 'SalesTourPlan' => $record->user?->employee,
            'SalesTourPlanDetail' => $record->tourPlan?->user?->employee,
            'Quote', 'SalesOrder', 'SalesInvoice', 'PurchaseOrder', 'GoodsReceiptNote',
            'InventoryAdjustment', 'InventoryTransfer', 'DeliveryChallan' => $requester->employee,
            default => throw new LogicException('No employee ownership mapping for this approval module.'),
        };
    }

    public function resolveRoute(?Employee $employee, string $module, ?int $territoryId = null, ?float $amount = null, ?string $date = null, ?int $requesterId = null): array
    {
        $module = class_basename($module);
        if (! ApprovalSetting::moduleRequiresApproval($module)) {
            throw new LogicException('Approval is disabled for this module.');
        }
        $flows = ApprovalFlow::query()->matching($module, $territoryId, $amount, $date)
            ->with('steps')->limit(2)->get();

        if ($flows->count() > 1) {
            throw new LogicException("Conflicting active approval flows match {$module}.");
        }
        $flow = $flows->first() ?? throw new LogicException("No approval flow found for module [{$module}]. Check the territory, amount and date.");

        $previous = null;
        $steps = [];
        $originals = [];
        $assignedUsers = [];
        $excluded = array_filter([$requesterId, $employee?->login_id]);

        foreach ($flow->steps as $step) {
            try {
                [$original, $reason] = match ($step->routing_type) {
                    'reporting_manager' => $this->managerApprover($employee),
                    'previous_manager' => $this->managerApprover($previous?->employee),
                    'specific_employee' => [$this->employeeLogin(Employee::query()->find($step->approver_employee_id)), 'Specific employee'],
                    'job_role' => [$this->roleApprover($step, $territoryId, $requesterId), 'Matching job role'],
                    default => throw new LogicException('Unknown approval step type.'),
                };
            } catch (LogicException $exception) {
                if (! $step->can_skip || $step->routing_type !== 'job_role' || $step->approver_employee_id || $step->approver_user_id || $exception->getMessage() !== 'No approver found for this job role.') {
                    throw new LogicException('Level '.$step->step_order.'. '.$exception->getMessage(), previous: $exception);
                }
                $steps[] = ['step' => $step, 'original' => null, 'assigned' => null, 'reason' => $exception->getMessage()];
                $previous = null;

                continue;
            }

            $assigned = $this->resolveDelegatedApprover($original, $module, $date);
            if ($requesterId !== null && in_array($requesterId, [$original->id, $assigned->id])) {
                throw new LogicException('The requester cannot approve their own request, including through delegation.');
            }
            if (in_array($original->id, $excluded) || in_array($assigned->id, $excluded)) {
                throw new LogicException('Employees cannot approve requests about themselves, including through delegation.');
            }
            if (isset($originals[$original->id]) || isset($assignedUsers[$assigned->id])
                || isset($assignedUsers[$original->id]) || isset($originals[$assigned->id])) {
                throw new LogicException('Each approval level must resolve to a different approver, including through delegation.');
            }
            $originals[$original->id] = true;
            $assignedUsers[$assigned->id] = true;
            $steps[] = compact('step', 'original', 'assigned', 'reason');
            $previous = $original;
        }
        if ($assignedUsers === []) {
            throw new LogicException('No actionable approval steps remain. Configure at least one available approver; automatic approval is not permitted.');
        }

        return compact('flow', 'steps');
    }

    public function managerApprover(?Employee $employee): array
    {
        if (! $employee?->is_active || $employee->is_deleted) {
            throw new LogicException('The employee concerned is missing or inactive.');
        }
        $resolution = app(EmployeeReportingService::class)->resolve($employee);
        if (! $resolution['manager']) {
            throw new LogicException($employee->employee_id.': '.$resolution['status'].'.');
        }

        return [$this->employeeLogin(Employee::query()->find($resolution['manager'])), $employee->full_name.' — '.$resolution['source']];
    }

    private function employeeLogin(?Employee $employee): User
    {
        if (! $employee?->is_active || $employee->is_deleted) {
            throw new LogicException('The selected employee is missing or inactive.');
        }

        return $employee->user ?? throw new LogicException($employee->employee_id.' needs a valid login to approve requests.');
    }

    private function roleApprover(ApprovalFlowStep $step, ?int $territoryId, ?int $requesterId): User
    {
        if (! $step->job_role_id) {
            throw new LogicException('Select a job role for this approval level.');
        }
        $query = $this->matchingApprovers($step->job_role_id, $territoryId, $step->territory_scope, $step->approver_employee_id || $step->approver_user_id ? null : $requesterId);
        if ($step->approver_employee_id) {
            $query->whereKey($this->employeeLogin(Employee::query()->find($step->approver_employee_id))->id);
        } elseif ($step->approver_user_id) {
            $query->whereKey($step->approver_user_id);
        }
        $candidates = $query->limit(2)->get();
        if ($candidates->count() > 1) {
            throw new LogicException('Several people match this role and territory. Choose a named approver for this step.');
        }

        return $candidates->first() ?? throw new LogicException('No approver found for this job role.');
    }

    public function validateRouteFor(User $requester, string $module, ?int $territoryId = null): void
    {
        if (ApprovalSetting::moduleRequiresApproval($module)) {
            $this->resolveRoute($requester->employee, $module, $territoryId, 0, requesterId: $requester->id);
        }
    }

    public function approverCandidates(int $jobRoleId, ?int $territoryId, string $territoryScope): Collection
    {
        return $this->matchingApprovers($jobRoleId, $territoryId, $territoryScope)->with('employee')->orderBy('name')->get();
    }

    private function matchingApprovers(int $jobRoleId, ?int $territoryId, string $territoryScope, ?int $requesterId = null): Builder
    {
        $territoryIds = $this->resolveTerritoryIds($territoryId, $territoryScope);

        return User::query()
            ->when($requesterId !== null, fn (Builder $query) => $query->whereKeyNot($requesterId))
            ->whereHas('employee', fn ($employeeQuery) => $employeeQuery
                ->where('is_active', true)
                ->whereHas('positions', fn ($positionQuery) => $positionQuery
                    ->where('job_role_id', $jobRoleId)
                    ->when($territoryIds !== null, fn ($query) => $query
                        ->whereHas('territories', fn ($territoryQuery) => $territoryQuery->whereIn('territories.id', $territoryIds))
                    )
                )
            );
    }

    protected function resolveDelegatedApprover(User $user, string $module, ?string $date = null): User
    {
        $delegations = ApprovalDelegation::query()
            ->activeFor($user->id, $module, $date)
            ->with('delegate.employee')
            ->limit(2)
            ->get();

        if ($delegations->count() > 1) {
            throw new LogicException('Multiple active delegations match this approver and module. Resolve the overlapping delegations.');
        }

        $delegation = $delegations->first();
        if ($delegation && (! $delegation->delegate || ! $delegation->delegate->employee?->is_active || $delegation->delegate->employee->is_deleted)) {
            throw new LogicException('The delegated approver must have an active employee record.');
        }

        return $delegation ? $delegation->delegate : $user;
    }

    /**
     * @return array<int>|null
     */
    protected function resolveTerritoryIds(?int $territoryId, string $territoryScope): ?array
    {
        if (! in_array($territoryScope, ['self', 'children', 'all'], true)) {
            throw new LogicException('Invalid approval territory scope.');
        }

        if ($territoryScope === 'all') {
            return null;
        }

        if ($territoryId === null) {
            throw new LogicException('A territory is required for territory-scoped approval steps.');
        }

        if ($territoryScope === 'self') {
            return [$territoryId];
        }

        $territoryIds = [$territoryId];
        $pending = [$territoryId];

        while ($pending !== []) {
            $children = Territory::query()
                ->whereIn('parent_territory_id', $pending)
                ->pluck('id')
                ->all();

            $pending = array_values(array_diff($children, $territoryIds));
            $territoryIds = array_values(array_unique([...$territoryIds, ...$children]));
        }

        return $territoryIds;
    }

    protected function resolveAmount(Model $approvable): float
    {
        return match (true) {
            isset($approvable->total) => (float) $approvable->total,
            isset($approvable->amount) => (float) $approvable->amount,
            isset($approvable->expected_value) => (float) $approvable->expected_value,
            default => 0.0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function submittedRecordSummary(Model $approvable): array
    {
        return [
            'class' => $approvable::class,
            'id' => $approvable->getKey(),
            'document_number' => method_exists($approvable, 'getDocumentNumber') ? $approvable->getDocumentNumber() : null,
            'attributes' => collect($approvable->attributesToArray())
                ->except(['created_by', 'updated_by', 'deleted_by', 'created_at', 'updated_at', 'deleted_at'])
                ->take(25)
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    protected function logActivity(
        Approval $approval,
        ApprovalActivityAction $action,
        ?ApprovalStep $step = null,
        ?int $actorId = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $comments = null,
        ?array $metadata = null
    ): void {
        ApprovalActivity::create([
            'approval_id' => $approval->id,
            'approval_step_id' => $step?->id,
            'actor_id' => $actorId ?? Auth::id(),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comments' => $comments,
            'metadata' => $metadata,
        ]);
    }
}
