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
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\LeaveApplication;
use App\Models\Territory;
use App\Models\User;
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
            $flow = $this->resolveApprovalFlow($module, $territoryId, $amount);

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
                'submitted_record_summary' => $this->submittedRecordSummary($approvable),
            ]);

            $selectedSteps = [];
            $selectedApprovers = [];

            foreach ($flow->steps as $flowStep) {
                $stepRole = $flowStep->jobRole;

                if (! $stepRole) {
                    if ($flowStep->can_skip) {
                        $this->logActivity($approval, ApprovalActivityAction::Skipped, metadata: [
                            'flow_step_id' => $flowStep->id,
                            'reason' => 'missing_job_role',
                        ]);

                        continue;
                    }

                    throw new LogicException("No job role configured for approval flow step [{$flowStep->id}].");
                }

                $user = $this->resolveApprover(
                    $flowStep->job_role_id,
                    $territoryId,
                    $flowStep->territory_scope
                );

                if (! $user && $flowStep->can_skip) {
                    $this->logActivity($approval, ApprovalActivityAction::Skipped, metadata: [
                        'flow_step_id' => $flowStep->id,
                        'reason' => 'no_approver_found',
                    ]);

                    continue;
                }

                if (! $user) {
                    throw new LogicException("No approver found for approval flow step [{$flowStep->step_order}].");
                }

                $assignedUser = $this->resolveDelegatedApprover($user, $module);

                if ($assignedUser->id === Auth::id()) {
                    throw new LogicException('The requester cannot approve their own request, including through delegation.');
                }

                if (in_array($approvable::class, [...HrApprovalHandler::supports(), LeaveApplication::class], true)
                    && (int) $approvable->employee?->login_id === (int) $assignedUser->id) {
                    throw new LogicException('An employee cannot approve an HR request about themselves.');
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
                    'territory_scope' => $flowStep->territory_scope,
                    'can_skip' => (bool) $flowStep->can_skip,
                    'sla_hours' => $flowStep->sla_hours,
                ];

                $selectedApprovers[] = [
                    'approval_step_id' => $step->id,
                    'step_order' => $step->step_order,
                    'user_id' => $assignedUser->id,
                    'name' => $assignedUser->name,
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

    protected function resolveApprovalFlow(string $module, ?int $territoryId, float $amount): ApprovalFlow
    {
        $flow = ApprovalFlow::query()
            ->where('module', $module)
            ->where('active', true)
            ->effectiveOn()
            ->where(fn ($query) => $query->whereNull('territory_id')->orWhere('territory_id', $territoryId))
            ->where(fn ($query) => $query->whereNull('min_amount')->orWhere('min_amount', '<=', $amount))
            ->where(fn ($query) => $query->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->with('steps.jobRole')
            ->orderByDesc('priority')
            ->orderByDesc('territory_id')
            ->orderByDesc('version')
            ->first();

        if (! $flow) {
            throw new LogicException("No approval flow found for module [{$module}]");
        }

        return $flow;
    }

    public function previewApprover(int $roleId, ?int $territoryId, string $scope, string $module): User
    {
        $user = $this->resolveApprover($roleId, $territoryId, $scope);
        if (! $user) {
            throw new LogicException('No active approver with a login matches this role and scope, excluding the requester.');
        }
        $user = $this->resolveDelegatedApprover($user, $module);
        if ($user->id === Auth::id()) {
            throw new LogicException('The requester cannot be their own delegated approver.');
        }

        return $user;
    }

    protected function resolveApprover(int $jobRoleId, ?int $territoryId, string $territoryScope = 'self'): ?User
    {
        $territoryIds = $this->resolveTerritoryIds($territoryId, $territoryScope);
        $candidates = User::query()
            ->whereKeyNot(Auth::id())
            ->whereHas('employee', fn ($employeeQuery) => $employeeQuery
                ->where('is_active', true)
                ->whereHas('positions', fn ($positionQuery) => $positionQuery
                    ->where('job_role_id', $jobRoleId)
                    ->when($territoryIds !== null, fn ($query) => $query
                        ->whereHas('territories', fn ($territoryQuery) => $territoryQuery->whereIn('territories.id', $territoryIds))
                    )
                )
            )
            ->limit(2)
            ->get();

        if ($candidates->count() > 1) {
            throw new LogicException("Multiple approvers match job role [{$jobRoleId}]. Narrow the role or territory assignments to one approver.");
        }

        return $candidates->first();
    }

    protected function resolveDelegatedApprover(User $user, string $module): User
    {
        $delegations = ApprovalDelegation::query()
            ->activeFor($user->id, $module)
            ->with('delegate.employee')
            ->limit(2)
            ->get();

        if ($delegations->count() > 1) {
            throw new LogicException('Multiple active delegations match this approver and module. Resolve the overlapping delegations.');
        }

        $delegation = $delegations->first();
        if ($delegation && (! $delegation->delegate || ! $delegation->delegate->employee?->is_active)) {
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
