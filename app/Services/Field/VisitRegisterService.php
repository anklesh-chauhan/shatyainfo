<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\SalesDcr;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitPreference;
use Illuminate\Database\Eloquent\Builder;

class VisitRegisterService
{
    private const EVIDENCE = [
        'fieldEvents', 'resolvedRecoveries', 'fieldOrders', 'geoAssessments', 'eDetailingPresentations',
    ];

    private const TRASHABLE_EVIDENCE = [
        'gpsPoints', 'media', 'visitDocumentLinks', 'followUps',
        'sgipDistribution', 'travelSegments', 'feedbacks', 'originalVisit',
    ];

    public function list(User $actor, array $filters): array
    {
        $team = ($filters['scope'] ?? null) === 'team';
        $query = $this->query($actor, $team);
        match ($filters['trash'] ?? 'active') {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => $query,
        };
        $employees = $team
            ? User::query()->whereIn('id', (clone $query)->select('employee_id'))->orderBy('name')->orderBy('id')->get(['id', 'name'])
            : [];
        $preferences = VisitPreference::current();
        $page = $this->withRegisterRelations($query)
            ->when($filters['employee_id'] ?? null, fn (Builder $rows, int $employeeId): Builder => $rows->where('employee_id', $employeeId))
            ->when($filters['approval_status'] ?? null, fn (Builder $rows, string $status): Builder => $rows->where('approval_status', $status))
            ->when($filters['search'] ?? null, fn (Builder $rows, string $search): Builder => $rows->whereLike('document_number', '%'.$search.'%'))
            ->orderByDesc('visit_date')->orderByDesc('id')->paginate(25)
            ->through(fn (Visit $visit): array => $this->summary($actor, $visit, $preferences));

        return ['data' => $page->items(), 'meta' => [
            'last_page' => $page->lastPage(),
            'can_view_team' => $this->query($actor, true)->withTrashed()->exists(),
            'employees' => $employees,
            'can_bulk_restore' => $actor->can('restoreAny', Visit::class),
            'can_bulk_force_delete' => $actor->can('forceDeleteAny', Visit::class),
        ]];
    }

    public function detail(User $actor, int $id, bool $team): array
    {
        $visit = $this->withRegisterRelations($this->query($actor, $team)->withTrashed())
            ->with(['outcome', 'visitPurposes'])->findOrFail($id);
        abort_unless($actor->can('view', $visit), 403);

        return [
            ...$this->summary($actor, $visit, VisitPreference::current()),
            'start_time' => $visit->start_time?->toIso8601String(),
            'end_time' => $visit->end_time?->toIso8601String(),
            'remarks' => $visit->remarks,
            'outcome' => $visit->outcome?->label,
            'purposes' => $visit->visitPurposes->pluck('name'),
        ];
    }

    public function apply(User $actor, array $ids, bool $team, string $action): int
    {
        if (count($ids) > 1 && $action !== 'delete') {
            abort_unless($actor->can($action.'Any', Visit::class), 403);
        }

        return $actor->getConnection()->transaction(function () use ($actor, $ids, $team, $action): int {
            $query = $this->query($actor, $team)->withTrashed()->whereKey($ids)->orderBy('id');
            $parents = (clone $query)->pluck('sales_dcr_id', 'id');
            abort_unless($parents->count() === count($ids), 404);
            $dcrs = SalesDcr::query()->whereKey($parents->filter()->unique()->values()->all())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locked = (clone $query)->lockForUpdate()->pluck('sales_dcr_id', 'id');
            abort_unless($locked->count() === count($ids), 404);
            abort_unless($parents->all() === $locked->all(), 409, 'The visit changed. Refresh the register.');
            $checkedDcrs = [];
            $preferences = VisitPreference::current();
            $records = $this->withRegisterRelations($query)->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $visit) {
                abort_unless($actor->can('view', $visit) && $actor->can($action, $visit), 403);
                abort_unless($visit->trashed() === ($action !== 'delete'), 409, 'The visit changed. Refresh the register.');
                abort_unless($this->isUnexecutedDraft($visit, $preferences), 422, 'Only drafts without linked field work can be changed here.');
                $dcr = $dcrs->get($visit->sales_dcr_id);
                if ($dcr && ! isset($checkedDcrs[$dcr->id])) {
                    app(FieldDcrService::class)->assertEditable($dcr);
                    app(FieldSyncRecoveryService::class)->assertNoPendingReview($dcr);
                    abort_if(app(FieldDeviceService::class)->blockingDevices($dcr)->isNotEmpty(), 422, 'Synchronize the pending field work for this date first.');
                    $checkedDcrs[$dcr->id] = true;
                }
            }

            foreach ($records as $visit) {
                $changed = match ($action) {
                    'delete' => $visit->delete(),
                    'restore' => $visit->restore(),
                    'forceDelete' => $visit->forceDelete(),
                };
                abort_unless($changed, 409, 'The visit could not be updated.');
            }

            return $records->count();
        }, attempts: 3);
    }

    private function query(User $actor, bool $team): Builder
    {
        abort_unless($actor->can(['ViewAny:Visit', 'View:Visit']), 403);

        return $team
            ? Visit::query()->applyVisibility('Visit', $actor)->where('employee_id', '!=', $actor->id)
            : Visit::query()->where('employee_id', $actor->id);
    }

    private function withRegisterRelations(Builder $query): Builder
    {
        return $query->with(['employee:id,name', 'patch:id,name', 'visitables.visitable', 'salesDcr.approval'])
            ->withExists([
                ...self::EVIDENCE,
                ...array_fill_keys(self::TRASHABLE_EVIDENCE, fn (Builder $evidence): Builder => $evidence->withTrashed()),
            ]);
    }

    private function isUnexecutedDraft(Visit $visit, VisitPreference $preferences): bool
    {
        if ($visit->visit_status !== 'draft' || $visit->approval_status !== 'draft'
            || $visit->start_time !== null || $visit->end_time !== null
            || $visit->checkin_latitude !== null || $visit->checkout_latitude !== null
            || $visit->checkin_longitude !== null || $visit->checkout_longitude !== null
            || $visit->image_latitude !== null || filled($visit->attachments)
            || $visit->image_longitude !== null || $visit->approved_at !== null
            || $visit->client_call_id !== null || $visit->flexible_plan_entry_id !== null
            || $visit->sales_tour_plan_id !== null || $visit->sales_tour_plan_detail_id !== null
            || $visit->rescheduled_visit_id !== null || $visit->reschedule_state !== 'none') {
            return false;
        }

        $dcr = $visit->salesDcr;
        if ($visit->sales_dcr_id && (! $dcr || (int) $dcr->user_id !== (int) $visit->employee_id
            || ! in_array($dcr->approval_status, ['draft', 'rejected'], true)
            || in_array($dcr->approval?->approval_status, ['pending', 'approved'], true)
            || ! $preferences->allowsDcrDate($dcr->dcr_date))) {
            return false;
        }

        return collect([...self::EVIDENCE, ...self::TRASHABLE_EVIDENCE])
            ->doesntContain(fn (string $relation): bool => (bool) $visit->getAttribute(str($relation)->snake()->append('_exists')->toString()));
    }

    private function summary(User $actor, Visit $visit, VisitPreference $preferences): array
    {
        $customer = $visit->visitables->firstWhere('visitable_type', AccountMaster::class)?->visitable;
        $actions = $visit->trashed() ? ['restore', 'forceDelete'] : ['delete'];

        return [
            'id' => $visit->id,
            'document_number' => $visit->document_number,
            'visit_date' => $visit->visit_date?->toDateString(),
            'visit_type' => $visit->visit_type,
            'visit_status' => $visit->visit_status,
            'approval_status' => $visit->approval_status,
            'employee' => $visit->employee?->name,
            'patch' => $visit->patch?->name,
            'primary_company' => $customer ? ['id' => $customer->id, 'name' => $customer->name] : null,
            'trashed' => $visit->trashed(),
            'can_open_workspace' => ! $visit->trashed() && (int) $visit->employee_id === (int) $actor->id,
            'actions' => $this->isUnexecutedDraft($visit, $preferences)
                ? array_values(array_filter($actions, fn (string $action): bool => $actor->can($action, $visit)))
                : [],
        ];
    }
}
