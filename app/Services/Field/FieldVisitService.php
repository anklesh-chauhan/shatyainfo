<?php

namespace App\Services\Field;

use App\Enums\FieldPlanningMode;
use App\Models\AccountMaster;
use App\Models\ImageTag;
use App\Models\Media;
use App\Models\NumberSeries;
use App\Models\SalesTourPlanDetail;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitFeedbackQuestion;
use App\Models\VisitPreference;
use App\Services\Visit\VisitEnforcementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldVisitService
{
    public function __construct(
        private VisitEnforcementService $enforcement,
    ) {}

    public function findOwned(User $user, int $visitId): Visit
    {
        $visit = Visit::query()
            ->with(['territory', 'patch', 'media.tags', 'visitables.visitable', 'sgipDistribution.items.item', 'fieldOrders.salesOrder', 'geoAssessments'])
            ->find($visitId);

        if (! $visit || (int) $visit->employee_id !== (int) $user->id) {
            abort(404, 'Visit not found.');
        }

        return $visit;
    }

    public function openDirect(User $user, array $data): Visit
    {
        abort_unless($user->can('create', Visit::class), 403);

        return $user->getConnection()->transaction(function () use ($user, $data): Visit {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = Visit::query()->where('client_call_id', $data['client_call_id'])->first();
            if ($existing) {
                abort_unless((int) $existing->employee_id === (int) $user->id, 404);
                if (app(FieldPlanConflictService::class)->matchesResolvedTarget($user, $existing, $data)) {
                    return $existing->load(['territory', 'patch', 'media.tags', 'visitables.visitable']);
                }
                abort_unless($existing->visit_date->toDateString() === $data['date']
                    && (int) $existing->primaryCompany()?->id === (int) $data['company_id']
                    && (int) $existing->flexible_plan_entry_id === (int) ($data['flexible_plan_entry_id'] ?? 0)
                    && (int) $existing->flexible_plan_version === (int) ($data['flexible_plan_version'] ?? 0)
                    && (string) $existing->unplanned_reason === (string) ($data['unplanned_reason'] ?? ''), 409, 'Call identifier already belongs to a different call.');

                return $existing->load(['territory', 'patch', 'media.tags', 'visitables.visitable']);
            }
            abort_unless(in_array(app(FieldPlanningService::class)->active()?->mode, [FieldPlanningMode::DirectDcr, FieldPlanningMode::Flexible], true), 422, 'Direct calls are not enabled.');
            app(VisitPreference::class)->current()->assertDcrDateIsReportable($data['date']);
            $account = app(FieldAccountService::class)->findVisible($user, $data['company_id']);
            $intention = ! empty($data['flexible_plan_entry_id']) ? app(FieldFlexiblePlanningService::class)->executable($user, (int) $data['flexible_plan_entry_id'], (int) ($data['flexible_plan_version'] ?? 0), (int) $account->id, $data['date']) : null;
            $dcr = app(FieldDcrService::class)->forDate($user, $data['date']);
            app(FieldDcrService::class)->assertEditable($dcr);
            $visit = Visit::create([
                'document_number' => NumberSeries::getNextNumber(Visit::class),
                'client_call_id' => $data['client_call_id'],
                'employee_id' => $user->id,
                'reporting_manager_id' => $user->reporting_manager_id,
                'sales_dcr_id' => $dcr->id,
                'visit_date' => $data['date'], 'visit_type' => $intention ? 'planned' : 'unplanned',
                'flexible_plan_entry_id' => $intention?->id, 'flexible_plan_version' => $intention?->version,
                'patch_id' => $intention?->patch_id, 'territory_id' => $intention?->patch?->territory_id,
                'visit_status' => 'draft', 'approval_status' => 'pending',
                'unplanned_reason' => $data['unplanned_reason'] ?? null,
            ]);
            NumberSeries::incrementNextNumber(Visit::class);
            $visit->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $account->id]);
            foreach (VisitFeedbackQuestion::query()->where('is_active', true)->get() as $question) {
                $visit->feedbacks()->create(['visit_feedback_question_id' => $question->id]);
            }

            return $visit->fresh(['territory', 'patch', 'media.tags', 'visitables.visitable']);
        });
    }

    public function openPlanned(User $user, int $detailId, int $patchId, int $companyId): Visit
    {
        return $user->getConnection()->transaction(function () use ($user, $detailId, $patchId, $companyId): Visit {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $detail = SalesTourPlanDetail::query()->with('tourPlan')->findOrFail($detailId);
            abort_unless((int) $detail->tourPlan?->user_id === (int) $user->id, 404, 'Tour day not found.');
            $patch = $detail->patches()->whereKey($patchId)->first();
            abort_unless($patch && $patch->companies()->whereKey($companyId)->exists(), 422, 'Select an account in a planned patch.');

            $existing = Visit::query()
                ->where('employee_id', $user->id)
                ->where('sales_tour_plan_detail_id', $detail->id)
                ->where('patch_id', $patchId)
                ->whereHas('visitables', fn ($query) => $query
                    ->where('visitable_type', AccountMaster::class)
                    ->where('visitable_id', $companyId))
                ->first();
            if ($existing) {
                $this->assertPlanning($existing);

                return $existing->load(['territory', 'patch', 'media.tags', 'visitables.visitable']);
            }

            $visit = Visit::create([
                'document_number' => NumberSeries::getNextNumber(Visit::class),
                'employee_id' => $user->id,
                'reporting_manager_id' => $user->reporting_manager_id,
                'sales_tour_plan_id' => $detail->sales_tour_plan_id,
                'sales_tour_plan_detail_id' => $detail->id,
                'territory_id' => $patch->territory_id,
                'patch_id' => $patch->id,
                'visit_date' => $detail->date,
                'visit_type' => 'planned',
                'visit_status' => 'draft',
                'approval_status' => 'pending',
                'remarks' => $detail->remarks,
                'is_joint_work' => ! empty($detail->joint_with),
            ]);
            NumberSeries::incrementNextNumber(Visit::class);
            $visit->visitables()->create([
                'visitable_type' => AccountMaster::class,
                'visitable_id' => $companyId,
            ]);
            if (! empty($detail->joint_with)) {
                $visit->jointUsers()->sync($detail->joint_with);
            }
            if (! empty($detail->visit_purpose_ids)) {
                $visit->visitPurposes()->sync($detail->visit_purpose_ids);
            }
            foreach (VisitFeedbackQuestion::query()->where('is_active', true)->orderBy('sort_order')->get() as $question) {
                $visit->feedbacks()->firstOrCreate(
                    ['visit_feedback_question_id' => $question->id],
                    ['created_by' => $user->id, 'updated_by' => $user->id],
                );
            }

            return $visit->fresh(['territory', 'patch', 'media.tags', 'visitables.visitable']);
        });
    }

    public function actPlanned(User $user, int $detailId, int $patchId, int $companyId, string $action, string $reason, ?string $date = null): Visit
    {
        $visit = $this->openPlanned($user, $detailId, $patchId, $companyId);
        abort_unless($visit->canBeCancelled(), 422, 'This visit is already finalized.');

        if ($action === 'reschedule') {
            $visit->update([
                'visit_status' => 'cancelled',
                'reschedule_state' => 'requested',
                'rescheduled_for' => $date,
                'cancel_reason' => $reason,
            ]);
        } else {
            $visit->update([
                'visit_status' => 'cancelled',
                'reschedule_state' => 'none',
                'rescheduled_for' => null,
                'cancel_reason' => $reason,
            ]);
        }

        return $visit->fresh(['territory', 'patch', 'media.tags', 'visitables.visitable']);
    }

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string}  $data
     */
    public function checkIn(User $user, Visit $visit, array $data): Visit
    {
        return DB::transaction(function () use ($user, $visit, $data): Visit {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($visit->salesDcr) {
                app(FieldDcrService::class)->assertEditable($visit->salesDcr);
            }

            return $this->performCheckIn($user, $visit, $data);
        });
    }

    private function performCheckIn(User $user, Visit $visit, array $data): Visit
    {
        $this->assertOwned($user, $visit);

        if ($visit->start_time) {
            throw ValidationException::withMessages([
                'visit' => 'Visit already checked in.',
            ]);
        }

        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;

        $this->enforcement->validateCheckIn($latitude, $longitude);
        $recordedAt = FieldRecordedAt::resolve($data);
        if (isset($data['recorded_at']) && $visit->visit_date?->toDateString() !== $recordedAt->toDateString()) {
            throw ValidationException::withMessages(['recorded_at' => 'Capture date must match the visit date.']);
        }

        $visit->update([
            'start_time' => $recordedAt,
            'checkin_latitude' => $latitude,
            'checkin_longitude' => $longitude,
            'visit_status' => 'started',
        ]);
        app(FieldGeoFenceService::class)->record($visit, 'check_in', $data, $recordedAt);

        return $visit->fresh(['territory', 'patch', 'media.tags', 'visitables.visitable']);
    }

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string}  $data
     * @return array{visit: Visit, needs_checkout_image: bool}
     */
    public function checkOut(User $user, Visit $visit, array $data): array
    {
        return DB::transaction(function () use ($user, $visit, $data): array {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($visit->salesDcr) {
                app(FieldDcrService::class)->assertEditable($visit->salesDcr);
            }

            return $this->performCheckOut($user, $visit, $data);
        });
    }

    private function performCheckOut(User $user, Visit $visit, array $data): array
    {
        $this->assertOwned($user, $visit);

        if ($visit->end_time && $visit->visit_status === 'completed') {
            throw ValidationException::withMessages([
                'visit' => 'Visit already checked out.',
            ]);
        }

        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;

        $recordedAt = FieldRecordedAt::resolve($data);
        if ($visit->start_time && $recordedAt->lessThan($visit->start_time)) {
            throw ValidationException::withMessages(['recorded_at' => 'Check-out cannot be before check-in.']);
        }
        $this->enforcement->validateCheckOut($visit, $latitude, $longitude, $recordedAt);

        $visit->update([
            'end_time' => $recordedAt,
            'checkout_latitude' => $latitude,
            'checkout_longitude' => $longitude,
            'visit_status' => 'checked_out_pending',
        ]);
        app(FieldGeoFenceService::class)->record($visit, 'check_out', $data, $recordedAt);

        $visit = $visit->fresh(['territory', 'patch', 'media.tags', 'visitables.visitable']);

        if ($this->enforcement->needsCheckOutImage($visit)) {
            return [
                'visit' => $visit,
                'needs_checkout_image' => true,
            ];
        }

        $visit->update(['visit_status' => 'completed']);

        return [
            'visit' => $visit->fresh(['territory', 'patch', 'media.tags', 'visitables.visitable']),
            'needs_checkout_image' => false,
        ];
    }

    /**
     * @param  array{tag: string, latitude: float|int|string, longitude: float|int|string, captured_at?: string|null}  $data
     */
    public function storePhoto(User $user, Visit $visit, UploadedFile $photo, array $data): Media
    {
        $this->assertOwned($user, $visit);

        $tag = $data['tag'];
        $this->ensureImageTag($tag);

        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;

        return DB::transaction(function () use ($visit, $photo, $tag, $latitude, $longitude, $data): Media {
            $path = $photo->store('visits/photos', 'public');

            $media = $visit->media()->create([
                'disk' => 'public',
                'path' => $path,
                'original_name' => $photo->getClientOriginalName(),
                'mime_type' => $photo->getClientMimeType(),
                'size' => $photo->getSize(),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'captured_at' => $data['captured_at'] ?? now(),
            ]);

            $media->attachTagBySlug($tag);

            if ($visit->visit_status === 'checked_out_pending'
                && ! $this->enforcement->needsCheckInImage($visit)
                && ! $this->enforcement->needsCheckOutImage($visit)
                && ! $this->enforcement->needsGeneralVisitImage($visit)) {
                $visit->update(['visit_status' => 'completed']);
            }

            return $media->fresh('tags');
        });
    }

    public function assertPlanning(Visit $visit): void
    {
        $policy = app(FieldPlanningService::class)->active();
        if ($policy) {
            app(FieldVisitPlanningPolicy::class)->validate($visit, $policy->rules, $visit->unplanned_reason);
        }
    }

    private function assertOwned(User $user, Visit $visit): void
    {
        if ((int) $visit->employee_id !== (int) $user->id) {
            abort(404, 'Visit not found.');
        }
        $this->assertPlanning($visit);
    }

    private function ensureImageTag(string $slug): void
    {
        ImageTag::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => str($slug)->replace('-', ' ')->title()->toString(),
                'is_active' => true,
            ],
        );
    }
}
