<?php

namespace App\Services\Field;

use App\Http\Requests\Api\V1\RequestFieldSyncRecoveryRequest;
use App\Http\Requests\Api\V1\StoreFieldVisitEventRequest;
use App\Http\Resources\Api\V1\VisitResource;
use App\Models\FieldOrderState;
use App\Models\FieldSyncRecovery;
use App\Models\FieldVisitEvent;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Visit;
use App\Services\Visit\VisitEnforcementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FieldReplacementService
{
    public function prepare(FieldSyncRecovery $record, array $input): array
    {
        $rules = array_filter((new RequestFieldSyncRecoveryRequest)->rules(), fn ($key) => str_starts_with($key, 'replacement'), ARRAY_FILTER_USE_KEY);
        $values = Validator::make(['resolution_mode' => 'replacement', 'replacement' => $input], $rules)->validate()['replacement'];
        $visit = $this->editableVisit($record);
        $amendedAt = CarbonImmutable::now()->startOfSecond();
        $original = $record->snapshot['request'];
        if ($original['action'] === 'samples') {
            $payload = ['items' => $values['items'] ?? [], 'sample_issue_id' => $values['sample_issue_id'] ?? null,
                'expected_version' => $visit->sgipDistribution()->first()?->fieldVersion()];
            $sources = app(FieldSgipStockService::class)->snapshot(User::query()->findOrFail($record->user_id))['sources'];
            abort_unless(collect($sources)->contains(fn ($source) => ($source['sample_issue_id'] ?? null) === ($payload['sample_issue_id'] === null ? null : (int) $payload['sample_issue_id'])), 422, 'Select a current stock allocation belonging to this representative.');
        } else {
            $changes = [];
            foreach ($values['items'] ?? [] as $line) {
                $changes[] = ['field' => 'line:'.$line['item_master_id'], 'value' => (float) $line['quantity'], 'updated_at' => $amendedAt->utc()->toIso8601String()];
            }
            foreach (['description', 'delivery_date'] as $field) {
                if ($values['change_'.$field] ?? false) {
                    $changes[] = ['field' => $field, 'value' => $values[$field] ?? ($field === 'description' ? '' : null), 'updated_at' => $amendedAt->utc()->toIso8601String()];
                }
            }
            $payload = ['order_id' => $original['payload']['order_id'], 'changes' => $changes];
        }
        $proposal = ['replacement_event_id' => (string) Str::uuid(), 'replacement_payload' => $payload, 'amended_at' => $amendedAt];
        $candidate = clone $record;
        $candidate->fill($proposal);
        $this->validatedEvent($candidate);

        return $proposal;
    }

    public function editableVisit(FieldSyncRecovery $record): Visit
    {
        $visit = app(FieldConflictResolutionService::class)->visit($record);
        abort_if($visit->isCancelled() || $visit->end_time || $visit->visit_status === 'completed', 422, 'A finalized call cannot receive a replacement.');
        if ($record->snapshot['request']['action'] === 'samples') {
            $distribution = $visit->sgipDistribution()->lockForUpdate()->first();
            abort_if($distribution && $distribution->approval_status !== 'draft', 422, 'Resolve the existing sample distribution through its approval workflow before proposing a replacement.');
        } else {
            $orderId = $record->snapshot['request']['payload']['order_id'] ?? null;
            abort_unless(is_string($orderId) && Str::isUuid($orderId), 422, 'The original order identity must be valid.');
            $state = FieldOrderState::query()->where('order_id', $orderId)->lockForUpdate()->first();
            if ($state) {
                abort_unless((int) $state->user_id === (int) $record->user_id && (int) $state->visit_id === (int) $visit->id, 404, 'Order not found for this call.');
                app(FieldOrderService::class)->assertEditable(SalesOrder::query()->lockForUpdate()->findOrFail($state->sales_order_id));
            }
        }

        return $visit;
    }

    public function validatedEvent(FieldSyncRecovery $record): array
    {
        $data = [...$record->snapshot['request'], 'event_id' => $record->replacement_event_id, 'payload' => $record->replacement_payload];
        $validationRequest = StoreFieldVisitEventRequest::create('/api/v1/field-visit-events', 'POST', $data);
        $validationRequest->headers->set('Idempotency-Key', $record->replacement_event_id);
        $validator = Validator::make($data, $validationRequest->rules());
        $validationRequest->withValidator($validator);

        return $validator->validate();
    }

    public function assertReviewable(FieldSyncRecovery $record): void
    {
        app(FieldConflictResolutionService::class)->assertUnchanged($record);
        $this->editableVisit($record);
        $this->validatedEvent($record);
        abort_unless($record->amended_at && $record->amended_at->lte(now()->addMinutes(5)), 422, 'A valid server-stamped amendment time is required.');
    }

    /** Consume the exact approved replacement inside the original receipt transaction. */
    public function apply(Request $request, FieldSyncRecovery $record): Response
    {
        abort_unless($record->status === 'approved' && $record->expires_at?->isFuture()
            && User::query()->find($record->reviewer_id)?->can('Review:FieldSyncRecovery'), 409, 'This replacement requires a current manager approval.');
        $this->assertReviewable($record);
        abort_if(FieldVisitEvent::query()->where('event_id', $record->operation_id)->exists(), 409, 'The original event already has a disposition.');
        $visit = app(FieldVisitEventService::class)->apply($request->user(), $this->validatedEvent($record), amendment: $record);
        $original = $record->snapshot['request'];
        FieldVisitEvent::query()->create([
            'event_id' => $record->operation_id, 'user_id' => $record->user_id, 'visit_id' => $visit->id, 'sales_dcr_id' => $visit->sales_dcr_id,
            'previous_event_id' => $record->replacement_event_id, 'action' => 'replaced', 'target' => $original['target'],
            'payload' => ['recovery_id' => $record->id, 'replacement_event_id' => $record->replacement_event_id,
                'original_action' => $original['action'], 'original_payload' => $original['payload'], 'original_previous_event_id' => $original['previous_event_id'] ?? null,
                'reviewer_id' => $record->reviewer_id, 'reason' => $record->reason, 'review_note' => $record->review_note],
            'captured_at' => $record->captured_at, 'received_at' => now(),
        ]);
        $enforcement = app(VisitEnforcementService::class);
        $visit->needs_check_in_image = $enforcement->needsCheckInImage($visit);
        $visit->needs_check_out_image = $enforcement->needsCheckOutImage($visit);

        return (new VisitResource($visit))->additional(['sync_resolution' => ['kind' => 'replacement', 'recovery_id' => $record->id,
            'replacement_event_id' => $record->replacement_event_id, 'replacement_payload' => $record->replacement_payload,
            'note' => 'Manager-reviewed corrected replacement posted; original evidence retained.']])->toResponse($request);
    }

    public function formDefaults(FieldSyncRecovery $record): array
    {
        $raw = $record->replacement_payload ?? ($record->snapshot['request']['payload'] ?? []);
        $payload = is_array($raw) ? $raw : [];
        if ($record->snapshot['request']['action'] === 'samples') {
            return ['items' => is_array($payload['items'] ?? null) ? array_values(array_filter($payload['items'], 'is_array')) : [], 'sample_issue_id' => $payload['sample_issue_id'] ?? null];
        }
        $values = ['items' => [], 'change_description' => false, 'change_delivery_date' => false];
        foreach (is_array($payload['changes'] ?? null) ? $payload['changes'] : [] as $change) {
            if (! is_array($change) || ! is_string($change['field'] ?? null)) {
                continue;
            }
            if (str_starts_with($change['field'], 'line:')) {
                $values['items'][] = ['item_master_id' => (int) substr($change['field'], 5), 'quantity' => $change['value'] ?? null];
            } elseif (in_array($change['field'], ['description', 'delivery_date'], true)) {
                $values['change_'.$change['field']] = true;
                $values[$change['field']] = $change['value'] ?? null;
            }
        }

        return $values;
    }
}
