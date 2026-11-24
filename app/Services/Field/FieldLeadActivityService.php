<?php

namespace App\Services\Field;

use App\Models\Approval;
use App\Models\ApprovalOverride;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\PositionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FieldLeadActivityService
{
    public function __construct(private FieldLeadService $leads) {}

    public function canCreate(User $actor): bool
    {
        return $actor->can('create', LeadActivity::class) && $this->canAccessLeads($actor);
    }

    public function canChange(User $actor, LeadActivity $activity, string $ability): bool
    {
        return $activity->lead !== null
            && $this->canAccessLeads($actor)
            && $actor->can(['view', $ability], $activity)
            && ($activity->approval?->approval_status !== 'approved' || $actor->can('overrideApproval', $activity));
    }

    public function canAccessLeads(User $actor): bool
    {
        return $actor->can(['ViewAny:Lead', 'View:Lead']);
    }

    /** @return Builder<User> */
    public function selectableUsers(User $actor): Builder
    {
        $users = User::query();
        if ($actor->hasAnyRole(['super_admin', 'administration_admin']) || $actor->can('AccessAllRecords')) {
            return $users;
        }

        return $users->whereKey(PositionService::getVisibleUserIdsFor($actor)->push($actor->id)->unique()->values()->all());
    }

    /** @param array<string, mixed> $input */
    public function create(User $actor, ?int $leadId, array $input): LeadActivity
    {
        abort_unless($this->canCreate($actor), 403);

        $lead = $this->leads->findVisible($actor, $leadId ?? (int) $input['lead_id']);
        $userId = $leadId === null ? $this->validateUser($actor, (int) $input['user_id']) : $actor->id;
        $activity = $lead->leadActivities()->create([
            'user_id' => $userId,
            'activity_type' => $input['activity_type'],
            'description' => $input['description'],
        ]);

        return $activity->load(['approval', 'user:id,name'])->setRelation('lead', $lead);
    }

    /** @param array<string, mixed> $input */
    public function update(User $actor, ?int $leadId, int $id, array $input): LeadActivity
    {
        return $actor->getConnection()->transaction(function () use ($actor, $leadId, $id, $input): LeadActivity {
            $activity = $this->lockedActivities($actor, $leadId, [$id], 'update')->sole();
            $lead = isset($input['lead_id']) && (int) $input['lead_id'] !== (int) $activity->lead_id
                ? $this->leads->findVisible($actor, (int) $input['lead_id'])
                : $activity->lead;

            $userId = $activity->user_id;
            if (isset($input['user_id']) && (int) $input['user_id'] !== (int) $userId) {
                $userId = $this->validateUser($actor, (int) $input['user_id']);
            }

            $activity->fill([
                'lead_id' => $lead->id,
                'user_id' => $userId,
                'activity_type' => $input['activity_type'],
                'description' => $input['description'],
            ]);
            if ($activity->isDirty()) {
                $this->auditOverride($actor, $activity, 'updated');
                abort_unless($activity->save(), 409, 'Could not update the activity.');
            }

            return $activity->load('user:id,name')->setRelation('lead', $lead);
        }, attempts: 3);
    }

    /** @param list<int> $ids */
    public function delete(User $actor, ?int $leadId, array $ids): int
    {
        return $actor->getConnection()->transaction(function () use ($actor, $leadId, $ids): int {
            $activities = $this->lockedActivities($actor, $leadId, $ids, 'delete');
            foreach ($activities as $activity) {
                $this->auditOverride($actor, $activity, 'deleted');
                abort_unless($activity->delete(), 409, 'Could not delete the selected activities.');
            }

            return $activities->count();
        }, attempts: 3);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, LeadActivity>
     */
    private function lockedActivities(User $actor, ?int $leadId, array $ids, string $ability): Collection
    {
        abort_unless($this->canAccessLeads($actor) && $actor->can(['ViewAny:LeadActivity', 'View:LeadActivity']), 403);

        $activities = $leadId === null
            ? LeadActivity::query()->applyVisibility('LeadActivity', $actor)
            : $this->leads->findVisible($actor, $leadId)->leadActivities()->getQuery();

        $records = $activities->whereKey($ids)
            ->with([
                'approval',
                'lead' => fn (BelongsTo $lead): Builder => $lead->getQuery()->applyVisibility('Lead', $actor)
                    ->with(['accountMaster:id,name', 'contactDetail:id,salutation,first_name,last_name']),
            ])->orderBy('id')->lockForUpdate()->get();

        abort_unless($records->count() === count($ids), 404, 'One or more activities are unavailable.');

        $approvals = Approval::query()->whereKey($records->pluck('approval.id')->filter()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($records as $activity) {
            $activity->setRelation('approval', $approvals->get($activity->approval?->id));
            abort_if($activity->lead === null, 404, 'One or more activities are unavailable.');
            abort_unless($actor->can(['view', $ability], $activity), 403);
            abort_if($activity->approval?->approval_status === 'approved' && $actor->cannot('overrideApproval', $activity), 422,
                'Approved activities require approval override to change.');
        }

        return $records;
    }

    private function validateUser(User $actor, int $userId): int
    {
        if (! $this->selectableUsers($actor)->whereKey($userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Select an available user.']);
        }

        return $userId;
    }

    private function auditOverride(User $actor, LeadActivity $activity, string $action): void
    {
        if ($activity->approval?->approval_status === 'approved') {
            ApprovalOverride::query()->create([
                'model_type' => LeadActivity::class,
                'model_id' => $activity->id,
                'user_id' => $actor->id,
                'reason' => "Lead activity {$action} through the mobile API",
            ]);
        }
    }
}
