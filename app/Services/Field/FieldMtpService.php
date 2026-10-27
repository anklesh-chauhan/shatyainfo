<?php

namespace App\Services\Field;

use App\Models\ApprovalStep;
use App\Models\Patch;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\Territory;
use App\Models\User;
use App\Models\VisitPreference;
use App\Models\VisitPurpose;
use App\Models\VisitType;
use App\Services\Approval\ApprovalService;
use App\Services\JointUserService;
use App\Services\PositionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

class FieldMtpService
{
    public function relations(): array
    {
        return ['user', 'details' => fn ($q) => $q->withCount('visits')->orderBy('date')->orderBy('id'), 'details.territory', 'details.patches', 'details.visitType', 'approval.steps.approver'];
    }

    public function query(User $user, bool $inbox = false): Builder
    {
        $query = SalesTourPlan::query()->with($this->relations());
        if (! $inbox) {
            return $query->where('user_id', $user->id);
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

    public function find(User $user, int $id, bool $lock = false): SalesTourPlan
    {
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
            && in_array($this->status($plan), ['draft', 'rejected'], true)
            && Gate::forUser($user)->allows('update', $plan);
    }

    public function canDecide(User $user, SalesTourPlan $plan): bool
    {
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
        $territories = Territory::query()->orderBy('name');
        if (! $user->hasAnyRole(['super_admin', 'administration_admin']) && ! $user->can('AccessAllRecords')) {
            $territories->whereIn('id', PositionService::getTerritoryIdsForUser($user));
        }
        $territories = $territories->get(['id', 'name']);

        return [
            'can_create' => Gate::forUser($user)->allows('create', SalesTourPlan::class),
            'tour_plan_months' => VisitPreference::current()->allowedTourPlanMonths(),
            'territories' => $territories->toArray(),
            'patches' => Patch::query()->whereIn('territory_id', $territories->pluck('id'))->orderBy('name')->get(['id', 'name', 'territory_id'])->toArray(),
            'visit_types' => VisitType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])->toArray(),
            'purposes' => VisitPurpose::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'visit_type_id'])->toArray(),
            'joint_users' => JointUserService::getJointUsersForUser($user)->orderBy('name')->get(['id', 'name'])->toArray(),
        ];
    }

    public function save(User $user, array $data, ?int $id = null): SalesTourPlan
    {
        return $user->getConnection()->transaction(function () use ($user, $data, $id): SalesTourPlan {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($id) {
                $plan = $this->find($user, $id, true);
                abort_unless($this->canEdit($user, $plan), 403, 'Only your editable drafts can be changed.');
                $this->assertRevision($plan, $data['revision']);
                abort_unless($plan->month->format('Y-m') === $data['month'], 422, 'The plan month cannot be changed.');
            } else {
                Gate::forUser($user)->authorize('create', SalesTourPlan::class);
                abort_if(SalesTourPlan::query()->where('user_id', $user->id)->where('month', 'like', $data['month'].'%')->exists(), 409, 'A plan already exists for this month. Open it instead.');
                $plan = new SalesTourPlan(['user_id' => $user->id, 'approval_status' => 'draft']);
                $plan->setRawAttributes([...$plan->getAttributes(), 'month' => $data['month']]);
            }
            $this->validateDetails($user, $data);
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

    public function submit(User $user, int $id, string $revision): SalesTourPlan
    {
        return $user->getConnection()->transaction(function () use ($user, $id, $revision): SalesTourPlan {
            $plan = $this->find($user, $id, true);
            abort_unless($this->canEdit($user, $plan), 403, 'Only your editable drafts can be submitted.');
            $this->assertRevision($plan, $revision);
            abort_if($plan->details->isEmpty(), 422, 'Add daily plans before submitting.');
            abort_unless($plan->details->contains(fn ($day) => $day->visit_type_id !== null || filled($day->remarks)), 422, 'Set a visit type or remarks for at least one day before submitting.');
            $this->validateDetails($user, [
                'month' => $plan->month->format('Y-m'),
                'details' => $plan->details->map(fn ($day) => [
                    'date' => $day->date->toDateString(), 'territory_id' => $day->territory_id,
                    'patch_ids' => $day->patches->pluck('id')->all(), 'visit_type_id' => $day->visit_type_id,
                    'visit_purpose_ids' => $day->visit_purpose_ids ?? [], 'joint_with' => $day->joint_with ?? [],
                ])->all(),
            ]);
            $territories = $plan->details->pluck('territory_id')->filter()->unique();
            if ($plan->approval) {
                $plan->approval->delete();
                $plan->unsetRelation('approval');
            }
            try {
                $approval = app(ApprovalService::class)->start($plan, SalesTourPlan::approvalModule(), $territories->count() === 1 ? (int) $territories->first() : null);
            } catch (LogicException $error) {
                throw ValidationException::withMessages(['approval' => $error->getMessage()]);
            }
            $plan->update(['approval_status' => $approval->approval_status, 'manager_remarks' => null, 'approved_by' => null, 'approved_at' => null]);

            return $plan->fresh($this->relations());
        });
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
        $options = $this->options($user);
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
