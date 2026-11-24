<?php

namespace App\Services\Field;

use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\Patch;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\Territory;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitPreference;
use App\Models\VisitPurpose;
use App\Models\VisitType;
use App\Services\Approval\ApprovalService;
use App\Services\JointUserService;
use App\Services\PositionService;
use App\Services\Travel\TravelSegmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FieldMtpService
{
    public function relations(): array
    {
        return ['user', 'details' => fn ($q) => $q->withCount('visits')->orderBy('date')->orderBy('id'), 'details.territory', 'details.patches', 'details.visitType', 'approval.steps.approver'];
    }

    public function query(User $user, bool $inbox = false): Builder
    {
        abort_unless(app(FieldPlanningService::class)->capabilities()['supports_mtp'], 403, 'Monthly tour plans are unavailable for the selected reporting method.');

        $query = SalesTourPlan::query()->with($this->relations());
        if (! $inbox) {
            return $query->where('user_id', $user->id);
        }

        if (! ApprovalSetting::moduleRequiresApproval(SalesTourPlan::class)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('user_id', '!=', $user->id)->whereHas('approval', function (Builder $q) use ($user): void {
            $q->where('approval_status', 'pending')->whereNull('finalized_at')
                ->whereIn('id', ApprovalStep::query()->select('approval_id')
                    ->where('assigned_user_id', $user->id)->where('status', 'pending')
                    ->where('step_order', function ($earlier): void {
                        $earlier->selectRaw('MIN(current_steps.step_order)')->from('approval_steps as current_steps')
                            ->whereColumn('current_steps.approval_id', 'approval_steps.approval_id')
                            ->where('current_steps.status', 'pending')->whereNull('current_steps.deleted_at');
                    }));
        });
    }

    public function teamQuery(User $user): Builder
    {
        abort_unless(app(FieldPlanningService::class)->capabilities()['supports_mtp'], 403, 'Monthly tour plans are unavailable for the selected reporting method.');
        abort_unless($this->canReadTeamPlans($user), 403);

        return $this->visibleTeammatePlans($user)->with($this->relations());
    }

    public function findTeam(User $user, int $id, bool $lock = false): SalesTourPlan
    {
        return $this->teamQuery($user)->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
    }

    private function canReadTeamPlans(User $user): bool
    {
        return $user->can('viewAny', SalesTourPlan::class) && $user->can('View:SalesTourPlan');
    }

    private function visibleTeammatePlans(User $user): Builder
    {
        return SalesTourPlan::query()->applyVisibility('SalesTourPlan', $user)->where('user_id', '!=', $user->id);
    }

    public function find(User $user, int $id, bool $lock = false): SalesTourPlan
    {
        abort_unless(app(FieldPlanningService::class)->capabilities()['supports_mtp'], 403, 'Monthly tour plans are unavailable for the selected reporting method.');

        $query = SalesTourPlan::query()->with($this->relations());
        if ($lock) {
            $query->lockForUpdate();
        }
        $plan = $query->findOrFail($id);
        abort_unless((int) $plan->user_id === (int) $user->id || $this->canDecide($user, $plan), 404, 'Monthly tour plan not found.');

        return $plan;
    }

    public function status(SalesTourPlan $plan): string
    {
        return $plan->approval?->approval_status ?? $plan->approval_status;
    }

    public function canEdit(User $user, SalesTourPlan $plan): bool
    {
        return (int) $plan->user_id === (int) $user->id
            && $this->canEditDraft($user, $plan);
    }

    public function canEditDraft(User $user, SalesTourPlan $plan): bool
    {
        return in_array($this->status($plan), ['draft', 'rejected'], true)
            && Gate::forUser($user)->allows('update', $plan);
    }

    public function canDecide(User $user, SalesTourPlan $plan): bool
    {
        if (! ApprovalSetting::moduleRequiresApproval(SalesTourPlan::class)) {
            return false;
        }
        $approval = $plan->approval;
        if ((int) $plan->user_id === (int) $user->id || ! $approval || $approval->isFinalized() || $approval->approval_status !== 'pending') {
            return false;
        }
        $step = $approval->steps->first(fn ($step) => $step->status->value === 'pending');

        return $step && (int) $step->assigned_user_id === (int) $user->id;
    }

    public function revision(SalesTourPlan $plan): string
    {
        return hash('sha256', json_encode([
            $plan->getAttributes(),
            $plan->details->map(fn ($detail) => [$detail->getAttributes(), $detail->patches->pluck('id')->sort()->values()->all()])->all(),
            $plan->approval?->getAttributes(),
            $plan->approval?->steps->map(fn ($step) => $step->getAttributes())->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function options(User $user): array
    {
        $options = $this->detailOptions($user);
        $canCreate = Gate::forUser($user)->allows('create', SalesTourPlan::class);
        $canReadTeam = $this->canReadTeamPlans($user);
        $employees = $canReadTeam && $canCreate ? $this->delegatedOwners($user)->orderBy('name')->get(['id', 'name']) : [];

        return [
            ...$options,
            'approval_required' => ApprovalSetting::moduleRequiresApproval(SalesTourPlan::class),
            'can_create' => $canCreate,
            'can_view_team' => $canReadTeam && (count($employees) > 0 || $this->visibleTeammatePlans($user)->exists()),
            'team_employees' => $employees,
            'tour_plan_months' => VisitPreference::current()->allowedTourPlanMonths(),
        ];
    }

    public function ownerOptions(User $user, int $ownerId): array
    {
        $owner = $this->delegatedOwner($user, $ownerId);

        return [...$this->detailOptions($owner), 'employee_name' => $owner->name];
    }

    public function teamEditOptions(User $user, int $id): array
    {
        $plan = $this->findTeam($user, $id);
        abort_unless($this->canEditDraft($user, $plan), 403, 'Only editable drafts can be changed.');

        return $this->detailOptions($plan->user);
    }

    private function delegatedOwners(User $user): Builder
    {
        return User::query()->whereKeyNot($user->id)->whereHas('employee')
            ->when(! $user->hasRole('Super Admin'), fn (Builder $query) => $query->whereIn('id', PositionService::getVisibleUserIdsFor($user)));
    }

    private function delegatedOwner(User $user, int $id): User
    {
        abort_unless(app(FieldPlanningService::class)->capabilities()['supports_mtp'] && $this->canReadTeamPlans($user), 403);
        Gate::forUser($user)->authorize('create', SalesTourPlan::class);

        return $this->delegatedOwners($user)->findOrFail($id);
    }

    private function detailOptions(User $user): array
    {
        abort_unless(app(FieldPlanningService::class)->capabilities()['supports_mtp'], 403, 'Monthly tour plans are unavailable for the selected reporting method.');

        $territories = Territory::query()->orderBy('name');
        if (! $user->hasAnyRole(['super_admin', 'administration_admin']) && ! $user->can('AccessAllRecords')) {
            $territories->whereIn('id', PositionService::getTerritoryIdsForUser($user));
        }
        $territories = $territories->get(['id', 'name']);

        return [
            'territories' => $territories->toArray(),
            'patches' => Patch::query()->whereIn('territory_id', $territories->pluck('id'))->orderBy('name')->get(['id', 'name', 'territory_id'])->toArray(),
            'visit_types' => VisitType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])->toArray(),
            'purposes' => VisitPurpose::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'visit_type_id'])->toArray(),
            'joint_users' => JointUserService::getJointUsersForUser($user)->orderBy('name')->get(['id', 'name'])->toArray(),
        ];
    }

    public function save(User $user, array $data, ?int $id = null): SalesTourPlan
    {
        abort_unless(app(FieldPlanningService::class)->capabilities()['supports_mtp'], 403, 'Monthly tour plans are unavailable for the selected reporting method.');

        return $user->getConnection()->transaction(function () use ($user, $data, $id): SalesTourPlan {
            $team = ($data['scope'] ?? null) === 'team';
            if ($id) {
                $plan = $team ? $this->findTeam($user, $id, true) : $this->find($user, $id, true);
                abort_unless($team ? $this->canEditDraft($user, $plan) : $this->canEdit($user, $plan), 403, 'Only editable drafts can be changed.');
                $this->assertRevision($plan, $data['revision']);
                abort_unless($plan->month->format('Y-m') === $data['month'], 422, 'The plan month cannot be changed.');
                $owner = $plan->user;
            } else {
                Gate::forUser($user)->authorize('create', SalesTourPlan::class);
                $owner = $team ? $this->delegatedOwner($user, (int) $data['user_id']) : $user;
                User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
                abort_if(SalesTourPlan::query()->where('user_id', $owner->id)->whereLike('month', $data['month'].'%')->lockForUpdate()->first(['id']), 409, 'A plan already exists for this employee and month. Open it instead.');
                $plan = new SalesTourPlan(['user_id' => $owner->id, 'approval_status' => 'draft']);
                $plan->setRawAttributes([...$plan->getAttributes(), 'month' => $data['month']]);
            }
            $this->validateDetails($owner, $data);
            $plan->save();
            $dates = array_column($data['details'], 'date');
            $removed = $plan->details()->whereNotIn('date', $dates)->get();
            foreach ($removed as $detail) {
                abort_if($detail->visits()->exists(), 422, 'A day with recorded visits cannot be removed.');
                $detail->patches()->detach();
                $detail->delete();
            }
            foreach ($data['details'] as $row) {
                $patchIds = array_unique($row['patch_ids']);
                unset($row['patch_ids']);
                $detail = $plan->details()->whereDate('date', $row['date'])->first();
                if ($detail) {
                    if ($this->detailMatches($detail, $row, $patchIds)) {
                        continue;
                    }
                    abort_if($detail->visits()->exists(), 422, 'A day with recorded visits cannot be edited.');
                    $detail->update($row);
                } else {
                    $detail = $plan->details()->create($row);
                }
                $detail->patches()->sync($patchIds);
            }
            $plan->touch();

            return $plan->fresh($this->relations());
        });
    }

    private function detailMatches(SalesTourPlanDetail $detail, array $row, array $patchIds): bool
    {
        $sameIds = static function (array $left, array $right): bool {
            $left = array_values(array_unique(array_map('intval', $left)));
            $right = array_values(array_unique(array_map('intval', $right)));
            sort($left);
            sort($right);

            return $left === $right;
        };

        return $detail->date->toDateString() === $row['date']
            && $detail->territory_id === $row['territory_id']
            && $detail->visit_type_id === $row['visit_type_id']
            && (string) ($detail->remarks ?? '') === (string) ($row['remarks'] ?? '')
            && $sameIds($detail->patches->pluck('id')->all(), $patchIds)
            && $sameIds($detail->visit_purpose_ids ?? [], $row['visit_purpose_ids'])
            && $sameIds($detail->joint_with ?? [], $row['joint_with']);
    }

    public function submit(User $user, int $id, string $revision, bool $team = false): SalesTourPlan
    {
        return $this->submitPlan($user, $id, $revision, $team);
    }

    public function canSubmitFromAdmin(User $user, SalesTourPlan $plan): bool
    {
        $gate = Gate::forUser($user);

        return $gate->allows('viewAny', SalesTourPlan::class)
            && $gate->allows('update', $plan)
            && (in_array($this->status($plan), ['draft', 'rejected'], true)
                || ($plan->approval_status === 'pending' && $plan->approval === null));
    }

    public function canDeleteFromRegister(User $user, SalesTourPlan $plan, bool $team): bool
    {
        $gate = Gate::forUser($user);

        return ($team || (int) $plan->user_id === (int) $user->id)
            && $this->canReadTeamPlans($user)
            && $gate->allows('delete', $plan)
            && ! in_array($this->status($plan), ['pending', 'submitted'], true)
            && ! in_array($plan->approval_status, ['pending', 'submitted'], true)
            && ($plan->approval_status !== 'approved' || $gate->allows('overrideApproval', $plan))
            && ! $plan->details->contains(fn (SalesTourPlanDetail $detail): bool => $detail->visits_count > 0);
    }

    public function deleteFromRegister(User $user, array $selection, bool $team): int
    {
        return $user->getConnection()->transaction(function () use ($user, $selection, $team): int {
            $plans = [];

            foreach (collect($selection)->sortBy('id') as $entry) {
                $plan = $team ? $this->findTeam($user, $entry['id'], true) : $this->find($user, $entry['id'], true);
                abort_unless($this->canDeleteFromRegister($user, $plan, $team), 403, 'This tour plan cannot be deleted.');
                $this->assertRevision($plan, $entry['revision']);
                abort_if(Visit::query()->where('sales_tour_plan_id', $plan->id)->exists(), 422, 'A plan with recorded visits cannot be deleted.');
                $plans[] = $plan;
            }

            foreach ($plans as $plan) {
                $plan->delete();
            }

            return count($plans);
        });
    }

    public function submitFromRegister(User $user, array $selection, bool $team): array
    {
        if ($team) {
            $this->teamQuery($user);
        }
        $submitted = 0;
        $firstError = null;

        foreach ($selection as $entry) {
            try {
                $this->submit($user, $entry['id'], $entry['revision'], $team);
                $submitted++;
            } catch (ValidationException|HttpExceptionInterface $exception) {
                $firstError ??= $exception->getMessage() ?: 'A selected tour plan could not be submitted.';
            } catch (ModelNotFoundException) {
                $firstError ??= 'A selected tour plan is no longer available.';
            }
        }

        return ['completed' => $submitted, 'skipped' => count($selection) - $submitted, 'error' => $firstError];
    }

    public function submitFromAdmin(User $user, int $id): SalesTourPlan
    {
        return $this->submitPlan($user, $id, null);
    }

    private function submitPlan(User $user, int $id, ?string $revision, bool $team = false): SalesTourPlan
    {
        $missingConfiguration = null;

        $plan = $user->getConnection()->transaction(function () use ($user, $id, $revision, $team, &$missingConfiguration): SalesTourPlan {
            if ($revision === null) {
                abort_unless(app(FieldPlanningService::class)->capabilities()['supports_mtp'], 403, 'Monthly tour plans are unavailable for the selected reporting method.');
                abort_unless(Gate::forUser($user)->allows('viewAny', SalesTourPlan::class), 403);
                $plan = SalesTourPlan::query()->applyVisibility('SalesTourPlan', $user)
                    ->with($this->relations())->lockForUpdate()->findOrFail($id);
                abort_unless($this->canSubmitFromAdmin($user, $plan), 403, 'This tour plan cannot be submitted.');
            } else {
                $plan = $team ? $this->findTeam($user, $id, true) : $this->find($user, $id, true);
                abort_unless($team ? $this->canSubmitFromAdmin($user, $plan) : $this->canEdit($user, $plan), 403, 'This tour plan cannot be submitted.');
                $this->assertRevision($plan, $revision);
            }

            abort_if($plan->details->isEmpty(), 422, 'Add daily plans before submitting.');
            abort_unless($plan->details->contains(fn ($day) => $day->visit_type_id !== null || filled($day->remarks)), 422, 'Set a visit type or remarks for at least one day before submitting.');
            $this->validateDetails($plan->user, [
                'month' => $plan->month->format('Y-m'),
                'details' => $plan->details->map(fn ($day) => [
                    'date' => $day->date->toDateString(), 'territory_id' => $day->territory_id,
                    'patch_ids' => $day->patches->pluck('id')->all(), 'visit_type_id' => $day->visit_type_id,
                    'visit_purpose_ids' => $day->visit_purpose_ids ?? [], 'joint_with' => $day->joint_with ?? [],
                ])->all(),
            ]);
            if ($plan->approval) {
                $plan->approval->delete();
                $plan->unsetRelation('approval');
            }

            if (! ApprovalSetting::moduleRequiresApproval(SalesTourPlan::class)) {
                $plan->update(['approval_status' => 'approved', 'manager_remarks' => null, 'approved_by' => null, 'approved_at' => now()]);
                app(TravelSegmentService::class)->regenerateFromTourPlan($plan);

                return $plan->fresh($this->relations());
            }
            $territories = $plan->details->pluck('territory_id')->filter()->unique();
            try {
                $approval = app(ApprovalService::class)->start($plan, SalesTourPlan::approvalModule(), $territories->count() === 1 ? (int) $territories->first() : null);
            } catch (LogicException $error) {
                $plan->update(['approval_status' => 'draft', 'manager_remarks' => null, 'approved_by' => null, 'approved_at' => null]);
                $missingConfiguration = $error->getMessage();

                return $plan->fresh($this->relations());
            }
            $plan->update(['approval_status' => $approval->approval_status, 'manager_remarks' => null, 'approved_by' => null, 'approved_at' => null]);

            return $plan->fresh($this->relations());
        });

        if ($missingConfiguration !== null) {
            throw ValidationException::withMessages(['approval' => $missingConfiguration]);
        }

        return $plan;
    }

    public function decide(User $user, int $id, array $data, bool $approve): SalesTourPlan
    {
        return $user->getConnection()->transaction(function () use ($user, $id, $data, $approve): SalesTourPlan {
            $plan = $this->find($user, $id, true);
            abort_unless($this->canDecide($user, $plan), 403, 'Only the assigned current approver may act.');
            $this->assertRevision($plan, $data['revision']);
            $service = app(ApprovalService::class);
            $accepted = $approve ? $service->approve($plan->approval, $user->id, $data['comments'] ?? null) : $service->reject($plan->approval, $user->id, $data['comments']);
            abort_unless($accepted, 409, 'This approval has changed. Refresh the plan.');
            $status = $plan->approval->fresh()->approval_status;
            $plan->update(['approval_status' => $status, 'manager_remarks' => $data['comments'] ?? null,
                'approved_by' => $status === 'approved' ? $user->id : null, 'approved_at' => $status === 'approved' ? now() : null]);

            return $plan->fresh($this->relations());
        });
    }

    private function assertRevision(SalesTourPlan $plan, string $revision): void
    {
        abort_unless(hash_equals($this->revision($plan), $revision), 409, 'This plan has changed. Reload it before continuing.');
    }

    private function validateDetails(User $user, array $data): void
    {
        $options = $this->detailOptions($user);
        foreach ($data['details'] as $index => $row) {
            $error = null;
            if (substr($row['date'], 0, 7) !== $data['month']) {
                $error = 'Every date must be within the selected month.';
            } elseif ($row['territory_id'] !== null && ! in_array($row['territory_id'], array_column($options['territories'], 'id'))) {
                $error = 'Select one of your assigned territories.';
            } elseif (array_diff($row['patch_ids'], array_column(array_filter($options['patches'], fn ($p) => $p['territory_id'] == $row['territory_id']), 'id'))) {
                $error = 'Select patches belonging to this territory.';
            } elseif ($row['visit_type_id'] !== null && ! in_array($row['visit_type_id'], array_column($options['visit_types'], 'id'))) {
                $error = 'Select an active visit type.';
            } elseif (array_diff($row['visit_purpose_ids'], array_column(array_filter($options['purposes'], fn ($p) => $p['visit_type_id'] == $row['visit_type_id']), 'id'))) {
                $error = 'Select purposes belonging to this visit type.';
            } elseif (array_diff($row['joint_with'], array_column($options['joint_users'], 'id'))) {
                $error = 'Select an eligible joint employee.';
            }
            if ($error) {
                throw ValidationException::withMessages(["details.$index" => $error]);
            }
        }
    }
}
