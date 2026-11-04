<?php

namespace App\Services\Field;

use App\Http\Resources\Api\V1\VisitResource;
use App\Models\FieldOrderState;
use App\Models\FieldSyncRecovery;
use App\Models\FieldVisitEvent;
use App\Models\SalesDcr;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Visit;
use App\Services\Visit\VisitEnforcementService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FieldConflictResolutionService
{
    public function eligible(FieldSyncRecovery $record): bool
    {
        return $record->path === 'api/v1/field-visit-events' && ! $record->recovered_at
            && in_array($record->snapshot['request']['action'] ?? null, ['samples', 'order'], true)
            && ($record->status !== 'pending_review' || in_array($record->resolution_mode, ['keep_server', 'replacement'], true));
    }

    /** Resolve only an existing owned call; never create a visit during conflict review. */
    public function visit(FieldSyncRecovery $record): Visit
    {
        $data = $record->snapshot['request'] ?? [];
        abort_unless($record->path === 'api/v1/field-visit-events' && in_array($data['action'] ?? null, ['samples', 'order'], true), 422, 'Only failed sample or order changes support keeping the server record.');
        abort_unless(($data['event_id'] ?? null) === $record->operation_id && $record->captured_at && $record->report_date
            && $record->captured_at->lte(now()->addMinutes(5)), 422, 'The original operation and capture time must be valid.');
        $target = $data['target'] ?? [];
        abort_unless(is_array($target) && is_array($data['payload'] ?? null), 422, 'The original call target and captured fields must be valid objects.');
        $previous = $data['previous_event_id'] ?? null;
        $prior = is_string($previous) && Str::isUuid($previous)
            ? FieldVisitEvent::query()->where('event_id', $previous)->where('user_id', $record->user_id)->first() : null;
        if (isset($target['visit_id']) && count($target) === 1) {
            $visitId = $target['visit_id'];
        } else {
            abort_unless($prior && Arr::sortRecursive($prior->target) === Arr::sortRecursive($target), 422, 'Synchronize this planned call preceding event before requesting resolution.');
            $visitId = $prior->visit_id;
        }
        $visit = Visit::query()->where('employee_id', $record->user_id)->lockForUpdate()->findOrFail($visitId);
        abort_unless(! $previous || ($prior && (int) $prior->visit_id === (int) $visit->id), 409, 'Synchronize the preceding event for this call first.');
        abort_unless($visit->start_time && $visit->visit_date?->toDateString() === $record->captured_at->toDateString(), 422, 'Resolve a captured change against its original checked-in call date.');
        $dcr = SalesDcr::query()->where('user_id', $record->user_id)->lockForUpdate()->findOrFail($visit->sales_dcr_id);
        $dcr->assertApprovalDocumentEditable();
        abort_unless(in_array($dcr->approval_status, ['draft', 'rejected'], true), 422, 'Resolve this DCR through its approval workflow first.');

        return $visit;
    }

    public function snapshot(FieldSyncRecovery $record): array
    {
        $visit = $this->visit($record);
        $data = $record->snapshot['request'];
        $result = ['visit' => $visit->only(['id', 'visit_status', 'start_time', 'end_time']), 'action' => $data['action']];
        if ($data['action'] === 'samples') {
            $distribution = $visit->sgipDistribution()->lockForUpdate()->first();
            $result['distribution'] = $distribution?->attributesToArray();
            $result['items'] = $distribution?->items()->orderBy('id')->lockForUpdate()->get()->toArray() ?? [];
        } else {
            $orderId = $data['payload']['order_id'] ?? null;
            abort_unless(is_string($orderId) && Str::isUuid($orderId), 422, 'The original order identity must be valid.');
            $state = FieldOrderState::query()->where('order_id', $orderId)->lockForUpdate()->first();
            abort_if($state && ((int) $state->user_id !== (int) $record->user_id || (int) $state->visit_id !== (int) $visit->id), 404, 'Order not found for this call.');
            $order = $state ? SalesOrder::query()->lockForUpdate()->findOrFail($state->sales_order_id) : null;
            $result['order_state'] = $state?->attributesToArray();
            $result['order'] = $order?->attributesToArray();
            $result['items'] = $order?->items()->orderBy('id')->lockForUpdate()->get()->toArray() ?? [];
        }

        return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function assertUnchanged(FieldSyncRecovery $record): void
    {
        abort_unless(Arr::sortRecursive($record->resolution_snapshot ?? []) === Arr::sortRecursive($this->snapshot($record)), 409,
            'The server call, sample or order record changed. Request a fresh review before resolving this upload.');
    }

    /** Called inside the idempotency transaction after the original request digest matches. */
    public function acknowledge(Request $request, FieldSyncRecovery $record): Response
    {
        abort_unless($record->status === 'approved' && $record->expires_at?->isFuture()
            && User::query()->find($record->reviewer_id)?->can('Review:FieldSyncRecovery'), 409,
            'Keeping the server record requires a current manager approval. The failed changes have not been applied.');
        $this->assertUnchanged($record);
        $visit = $this->visit($record);
        $data = $record->snapshot['request'];
        abort_if(FieldVisitEvent::query()->where('event_id', $record->operation_id)->exists(), 409, 'This operation already has a field event. Replay its original receipt.');
        FieldVisitEvent::query()->create([
            'event_id' => $record->operation_id, 'user_id' => $record->user_id, 'visit_id' => $visit->id, 'sales_dcr_id' => $visit->sales_dcr_id,
            'previous_event_id' => $data['previous_event_id'] ?? null, 'action' => 'reconciled', 'target' => $data['target'],
            'payload' => ['resolution' => 'keep_server', 'recovery_id' => $record->id, 'original_action' => $data['action'],
                'original_payload' => $data['payload'] ?? [], 'server_snapshot' => $record->resolution_snapshot,
                'reviewer_id' => $record->reviewer_id, 'reason' => $record->reason, 'review_note' => $record->review_note],
            'captured_at' => $record->captured_at, 'received_at' => now(),
        ]);
        $visit = app(FieldVisitWorkspaceService::class)->load(app(FieldVisitService::class)->findOwned($request->user(), $visit->id));
        $enforcement = app(VisitEnforcementService::class);
        $visit->needs_check_in_image = $enforcement->needsCheckInImage($visit);
        $visit->needs_check_out_image = $enforcement->needsCheckOutImage($visit);

        return (new VisitResource($visit))->additional(['sync_resolution' => ['kind' => 'keep_server', 'recovery_id' => $record->id,
            'note' => 'Manager reviewed: server record retained; failed changes were not applied.']])->toResponse($request);
    }
}
