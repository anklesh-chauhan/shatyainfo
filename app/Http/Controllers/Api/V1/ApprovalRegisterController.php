<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApprovalRegisterLifecycleRequest;
use App\Http\Requests\Api\V1\ListApprovalRegisterRequest;
use App\Models\Approval;
use App\Models\ApprovalActivity;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalRegisterController extends Controller
{
    public function index(ListApprovalRegisterRequest $request): JsonResponse
    {
        $user = $request->user();
        $trash = $request->validated('trash', 'active');
        $query = $this->query($user);
        match ($trash) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => $query,
        };

        $page = $query->when($request->validated('status'), fn (Builder $rows, string $status): Builder => $rows->where('approval_status', $status))
            ->with(['requester:id,name', 'territory:id,name'])
            ->latest('id')->paginate(25)->through(fn (Approval $approval): array => $this->summary($user, $approval));

        return response()->json([...$page->toArray(), 'bulk_actions' => $this->bulkActions($user)]);
    }

    public function show(Request $request, int $approval): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Approval::class), 403);
        $record = $this->query($user)->withTrashed()->with([
            'requester:id,name', 'territory:id,name', 'steps.approver:id,name', 'activities.actor:id,name',
        ])->findOrFail($approval);
        abort_unless($user->can('view', $record), 403);

        $snapshot = $record->submitted_record_summary ?? [];

        return response()->json(['data' => [
            ...$this->summary($user, $record),
            'flow' => $snapshot['flow_name'] ?? null,
            'completed_at' => $record->completed_at?->toIso8601String(),
            'finalized_at' => $record->finalized_at?->toIso8601String(),
            'document_number' => $snapshot['document_number'] ?? null,
            'context' => (object) array_intersect_key($snapshot['attributes'] ?? [], array_flip(['month', 'visit_date', 'dcr_date', 'start_date', 'end_date'])),
            'steps' => $record->steps->map(fn (ApprovalStep $step): array => [
                'order' => $step->step_order, 'reviewer' => $step->approver?->name,
                'status' => $step->status->value, 'due_at' => $step->due_at?->toIso8601String(),
            ]),
            'activity' => $record->activities->map(fn (ApprovalActivity $event): array => [
                'at' => $event->created_at?->toIso8601String(), 'action' => $event->action->value,
                'actor' => $event->actor?->name, 'from' => $event->from_status, 'to' => $event->to_status,
                'comments' => $event->comments,
            ]),
        ]]);
    }

    public function lifecycle(ApprovalRegisterLifecycleRequest $request): JsonResponse
    {
        $user = $request->user();
        $ids = array_map('intval', $request->validated('ids'));
        $action = $request->validated('action');
        abort_if(count($ids) > 1 && ! $user->can($action.'Any', Approval::class), 403);

        return $user->getConnection()->transaction(function () use ($user, $ids, $action): JsonResponse {
            $records = $this->query($user)->withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);

            foreach ($records as $record) {
                abort_unless($user->can('view', $record) && $user->can($action, $record), 403);
                abort_unless($record->trashed() === ($action !== 'delete'), 409, 'The approval changed. Refresh the register.');
                abort_if($record->approval_status === 'approved' && ! $user->can('overrideApproval', $record), 403);
            }

            foreach ($records as $record) {
                $changed = match ($action) {
                    'delete' => $record->delete(),
                    'restore' => $record->restore(),
                    'forceDelete' => $record->forceDelete(),
                };
                abort_unless($changed, 409, 'The approval could not be updated.');
            }

            return response()->json(['data' => ['updated' => $records->count()]]);
        });
    }

    private function query(User $user): Builder
    {
        return Approval::query()->visibleTo($user)->availableOnMobile();
    }

    /** @return list<string> */
    private function bulkActions(User $user): array
    {
        return array_values(array_filter(['delete', 'restore', 'forceDelete'], fn (string $action): bool => $user->can($action.'Any', Approval::class)));
    }

    /** @return array<string, mixed> */
    private function summary(User $user, Approval $approval): array
    {
        $canView = $user->can('view', $approval);
        $actions = $approval->trashed() ? ['restore', 'forceDelete'] : ['delete'];

        return [
            'id' => $approval->id, 'module' => class_basename($approval->module ?? $approval->approvable_type),
            'record_id' => $approval->record_id ?? $approval->approvable_id,
            'status' => $approval->approval_status, 'requested_by' => $approval->requester?->name,
            'requested_at' => $approval->created_at?->toIso8601String(),
            'amount' => $approval->requested_amount, 'territory' => $approval->territory?->name,
            'flow_version' => $approval->flow_version,
            'trashed' => $approval->trashed(), 'can_view' => $canView,
            'actions' => $canView && ($approval->approval_status !== 'approved' || $user->can('overrideApproval', $approval))
                ? array_values(array_filter($actions, fn (string $action): bool => $user->can($action, $approval)))
                : [],
        ];
    }
}
