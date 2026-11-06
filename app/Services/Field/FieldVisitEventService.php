<?php

namespace App\Services\Field;

use App\Models\FieldSyncRecovery;
use App\Models\FieldVisitEvent;
use App\Models\SalesDcr;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class FieldVisitEventService
{
    public function __construct(private FieldVisitService $visits,
        private FieldVisitWorkspaceService $workspace, private FieldDcrService $dcrs) {}

    public function apply(User $user, array $data, ?UploadedFile $photo = null, ?FieldSyncRecovery $amendment = null): Visit
    {
        return $user->getConnection()->transaction(function () use ($user, $data, $photo, $amendment): Visit {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if(FieldVisitEvent::query()->where('event_id', $data['event_id'])->exists(), 409, 'This event UUID has already been used. Replay its original request.');
            $capturedAt = FieldRecordedAt::resolve(['recorded_at' => $data['captured_at']]);
            $target = $data['target'];
            $visit = isset($target['visit_id'])
                ? $this->visits->findOwned($user, $target['visit_id'])
                : $this->visits->openPlanned($user, $target['detail_id'], $target['patch_id'], $target['company_id']);
            $dcr = SalesDcr::query()->lockForUpdate()->findOrFail($visit->sales_dcr_id);
            $this->dcrs->assertOwned($user, $dcr);
            $this->dcrs->assertEditable($dcr);
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            abort_if($visit->isCancelled() || ($visit->visit_status === 'completed' && $data['action'] !== 'photo'), 422, 'This visit is finalized. Refresh and reconcile this call.');
            abort_unless($visit->visit_date?->toDateString() === $capturedAt->toDateString(), 422, 'Capture date must match the visit date.');
            if (! empty($data['previous_event_id'])) {
                abort_unless(FieldVisitEvent::query()->where('event_id', $data['previous_event_id'])
                    ->where('user_id', $user->id)->where('visit_id', $visit->id)->exists(),
                    409, 'Synchronize the preceding event for this call first.');
            }
            $payload = $data['payload'];
            $location = Arr::only($payload, ['latitude', 'longitude', 'accuracy_meters']);
            $location['recorded_at'] = $data['captured_at'];
            switch ($data['action']) {
                case 'order':
                    abort_unless($visit->start_time && ! $visit->end_time, 422, 'Book orders during a checked-in visit.');
                    app(FieldOrderService::class)->apply($user, $visit, $payload, $data['event_id'], $amendment?->amended_at ?? $capturedAt, $amendment !== null);
                    break;
                case 'samples':
                    abort_unless($visit->start_time && ! $visit->end_time, 422, 'Record distribution during a checked-in visit.');
                    $existing = $visit->sgipDistribution()->lockForUpdate()->first();
                    abort_unless($existing ? hash_equals($existing->fieldVersion(), (string) $payload['expected_version']) : $payload['expected_version'] === null,
                        409, 'Sample distribution changed on the server. Refresh and reconcile before retrying.');
                    $sgip = app(FieldSgipService::class);
                    $sgip->upsert($user, $visit, ['items' => $payload['items'], 'sample_issue_id' => $payload['sample_issue_id'] ?? null]);
                    $accepted = $sgip->submit($user, $visit);
                    $payload['distribution_id'] = $accepted->id;
                    break;
                case 'check_in':
                    $visit = $this->visits->checkIn($user, $visit, $location);
                    break;
                case 'details':
                    abort_unless($visit->start_time && ! $visit->end_time, 422, 'Add call details after check-in and before check-out.');
                    $details = Arr::only($payload, ['remarks', 'visit_outcome_id', 'visit_purpose_ids', 'is_joint_work', 'joint_user_ids', 'start_time', 'end_time', 'next_follow_up_date', 'feedback']);
                    if (array_key_exists('remarks', $details)) {
                        $details['remarks'] = trim(implode("\n", array_filter([(string) $visit->remarks, trim((string) $details['remarks'])], fn (string $note): bool => $note !== '')));
                    }
                    $visit = $this->workspace->save($user, $visit, $details);
                    break;
                case 'photo':
                    abort_unless($visit->start_time && $photo, 422, 'Check in before capturing a visit photo.');
                    $media = $this->visits->storePhoto($user, $visit, $photo, [
                        ...$location, 'tag' => $payload['tag'], 'captured_at' => $data['captured_at'],
                    ]);
                    $payload['media_id'] = $media->id;
                    $payload['photo_sha256'] = hash_file('sha256', $photo->getRealPath());
                    break;
                case 'check_out':
                    $visit = $this->visits->checkOut($user, $visit, $location)['visit'];
                    break;
            }
            if ($amendment) {
                $payload['amendment'] = ['recovery_id' => $amendment->id, 'replaces_event_id' => $amendment->operation_id,
                    'amended_at' => $amendment->amended_at->toIso8601String(), 'reviewer_id' => $amendment->reviewer_id];
            }
            FieldVisitEvent::query()->create([
                'event_id' => $data['event_id'], 'user_id' => $user->id,
                'visit_id' => $visit->id, 'sales_dcr_id' => $dcr->id,
                'previous_event_id' => $data['previous_event_id'] ?? null,
                'action' => $data['action'], 'target' => $target, 'payload' => $payload,
                'captured_at' => $capturedAt, 'received_at' => now(),
            ]);

            return $this->workspace->load($this->visits->findOwned($user, $visit->id));
        });
    }
}
