<?php

namespace App\Services\Field;

use App\Models\FieldSyncRecovery;
use App\Models\SalesDcr;
use App\Models\User;
use App\Models\VisitPreference;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldSyncRecoveryService
{
    public static function allowsDate(string $date): bool
    {
        $context = request()->attributes->get('field_reporting_recovery');

        return is_array($context) && $context['user_id'] === auth()->id() && $context['date'] === $date;
    }

    public static function allowsCaptureTime(CarbonImmutable $time): bool
    {
        $context = request()->attributes->get('field_reporting_recovery');

        return is_array($context) && $context['user_id'] === auth()->id() && $context['captured_date'] === $time->toDateString();
    }

    public function recordFailure(Request $request, string $key, string $hash, array $files, string $message): void
    {
        if (! $this->supported($request) || strlen(json_encode($request->except(array_keys($request->allFiles())), JSON_THROW_ON_ERROR)) > 131072) {
            return;
        }
        $time = null;
        try {
            $raw = $request->input('captured_at', $request->input('meta.captured_at'));
            $time = is_string($raw) ? CarbonImmutable::parse($raw)->setTimezone(config('app.timezone')) : null;
            if ($time && ($time->year < 1971 || $time->gt(now()->addDay()))) {
                $time = null;
            }
        } catch (\Throwable) {
        }
        $date = $time?->toDateString();
        if ($request->is('api/v1/visits/direct')) {
            $input = $request->input('date');
            $date = is_string($input) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) && $time?->toDateString() === $input ? $input : null;
        }
        if ($request->is('api/v1/field-expense-events')) {
            try {
                $input = $request->input('date');
                $parsed = is_string($input) ? CarbonImmutable::createFromFormat('!Y-m-d', $input) : null;
                $date = $parsed && $parsed->toDateString() === $input && $parsed->year >= 1971 && $parsed->lte(today()) ? $input : null;
            } catch (\Throwable) {
                $date = null;
            }
        }
        if (preg_match('~^api/v1/dcrs/(\d+)/expenses~', $request->path(), $match)) {
            $date = SalesDcr::query()->where('user_id', $request->user()->id)->find($match[1])?->dcr_date?->toDateString();
        }
        DB::transaction(function () use ($request, $key, $hash, $files, $message, $time, $date): void {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $record = FieldSyncRecovery::query()->where('user_id', $request->user()->id)->where('operation_id', $key)->lockForUpdate()->first();
            if ($record) {
                if (hash_equals($record->request_hash, $hash)) {
                    $record->update(['attempts' => $record->attempts + 1, 'last_error' => mb_substr($message, 0, 4000)]);
                }

                return;
            }
            FieldSyncRecovery::query()->create(['user_id' => $request->user()->id, 'operation_id' => $key, 'request_hash' => $hash,
                'path' => $request->path(), 'report_date' => $date, 'captured_at' => $time,
                'snapshot' => ['request' => $request->except(array_keys($request->allFiles())), 'file_sha256' => $files],
                'last_error' => mb_substr($message, 0, 4000), 'status' => 'failed']);
        });
    }

    private function supported(Request $request): bool
    {
        return $request->isMethod('POST') && (in_array($request->path(), ['api/v1/field-visit-events', 'api/v1/field-dcr-events', 'api/v1/rcpa-observations', 'api/v1/field-expense-events', 'api/v1/visits/direct'], true)
            || preg_match('~^api/v1/dcrs/\d+/expenses(?:/\d+/receipt)?$~', $request->path()));
    }

    public function needsWindowException(FieldSyncRecovery $record): bool
    {
        return $record->report_date && $record->report_date->lte(today())
            && (! VisitPreference::current()->allowsDcrDate($record->report_date->toDateString())
                || $record->captured_at?->lt(now()->subDays(7)));
    }

    public function requestReview(User $user, FieldSyncRecovery $record, array $data): FieldSyncRecovery
    {
        abort_unless((int) $record->user_id === (int) $user->id, 404);

        return DB::transaction(function () use ($user, $record, $data): FieldSyncRecovery {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $record = FieldSyncRecovery::query()->lockForUpdate()->findOrFail($record->id);
            $mode = $data['resolution_mode'] ?? 'retry_original';
            $snapshot = null;
            $replacement = ['replacement_event_id' => null, 'replacement_payload' => null, 'amended_at' => null];
            if ($mode === FieldPlanConflictService::MODE) {
                abort_unless(($data['confirmed_actual_visit'] ?? false) === true, 422, 'Confirm the captured visit occurred and should be accepted as unplanned.');
                abort_if($record->recovered_at, 409, 'This call is already resolved. Retain its recorded resolution.');
                $snapshot = app(FieldPlanConflictService::class)->snapshot($record, $data['reason']);
            } elseif (in_array($mode, ['keep_server', 'replacement'], true)) {
                $conflicts = app(FieldConflictResolutionService::class);
                abort_unless($conflicts->eligible($record), 409, 'This operation cannot request conflict resolution.');
                if ($mode === 'replacement') {
                    abort_unless(($data['confirmed_replacement'] ?? false) === true, 422, 'Confirm the corrected transaction matches the actual distribution or customer order.');
                    $replacement = app(FieldReplacementService::class)->prepare($record, $data['replacement'] ?? []);
                } else {
                    abort_unless(($data['confirmed_no_pending_obligation'] ?? false) === true, 422, 'Confirm that physical distribution and customer order obligations are reconciled before keeping the server record.');
                }
                $snapshot = $conflicts->snapshot($record);
            } else {
                abort_unless($record->resolution_mode === 'retry_original' || $record->recovered_at, 409, 'Continue the recorded conflict review; its failed changes cannot resume automatically.');
                abort_unless($record->status === 'failed' || (in_array($record->status, ['approved', 'recovered'], true) && $record->expires_at?->isPast()), 409, 'This operation is already under review, rejected or has a current authorization.');
                abort_unless($this->needsWindowException($record), 422, 'This failure does not need a reporting-window exception. Resolve its master, stock or document error and retry the original record.');
                if ($record->recovered_at) {
                    $mode = $record->resolution_mode;
                    $snapshot = $record->resolution_snapshot;
                    $replacement = $record->only(['replacement_event_id', 'replacement_payload', 'amended_at']);
                }
            }
            $reviewer = User::query()->findOrFail($data['reviewer_id']);
            abort_unless((int) $reviewer->id !== (int) $user->id && $reviewer->can('Review:FieldSyncRecovery'), 422, 'Choose an authorized manager other than yourself.');
            $history = $record->review_history ?? [];
            if ($record->requested_at) {
                $history[] = $record->only(['reviewer_id', 'reason', 'review_note', 'requested_at', 'reviewed_at', 'expires_at', 'status', 'resolution_mode', 'resolution_snapshot', 'replacement_event_id', 'replacement_payload', 'amended_at']);
            }
            $record->update(['status' => 'pending_review', 'reviewer_id' => $reviewer->id, 'reason' => $data['reason'], 'requested_at' => now(),
                'reviewed_at' => null, 'review_note' => null, 'expires_at' => null, 'review_history' => $history,
                'resolution_mode' => $mode, 'resolution_snapshot' => $snapshot, ...$replacement]);

            return $record;
        });
    }

    public function review(User $user, FieldSyncRecovery $record, array $data): FieldSyncRecovery
    {
        abort_unless($user->can('Review:FieldSyncRecovery') && (int) $record->reviewer_id === (int) $user->id && (int) $record->user_id !== (int) $user->id, 403);

        return DB::transaction(function () use ($user, $record, $data): FieldSyncRecovery {
            User::query()->whereKey($record->user_id)->lockForUpdate()->firstOrFail();
            $record = FieldSyncRecovery::query()->lockForUpdate()->findOrFail($record->id);
            abort_unless((int) $record->reviewer_id === (int) $user->id && $user->can('Review:FieldSyncRecovery'), 403);
            abort_unless($record->status === 'pending_review', 409, 'This request has already been reviewed.');
            if (in_array($record->resolution_mode, ['keep_server', 'replacement', FieldPlanConflictService::MODE], true) && ! $record->recovered_at) {
                abort_unless(hash_equals($record->reviewToken(), (string) ($data['review_token'] ?? '')), 409, 'This review request changed. Refresh and inspect its current evidence before deciding.');
            }
            if ($data['decision'] === 'approved') {
                if ($record->resolution_mode === FieldPlanConflictService::MODE && ! $record->recovered_at) {
                    abort_unless(($data['confirmed_actual_visit'] ?? false) === true, 422, 'Verify the actual visit and its acceptance as unplanned before approval.');
                    app(FieldPlanConflictService::class)->assertUnchanged($record);
                } elseif ($record->resolution_mode === 'replacement' && ! $record->recovered_at) {
                    abort_unless(($data['confirmed_replacement'] ?? false) === true, 422, 'Verify that the corrected transaction matches the physical distribution or customer order before approval.');
                    app(FieldReplacementService::class)->assertReviewable($record);
                } elseif ($record->resolution_mode === 'keep_server' && ! $record->recovered_at) {
                    abort_unless(($data['confirmed_no_pending_obligation'] ?? false) === true, 422, 'Confirm physical distribution and customer order obligations are reconciled.');
                    app(FieldConflictResolutionService::class)->assertUnchanged($record);
                } else {
                    abort_unless($this->needsWindowException($record), 422, 'A reporting-window exception is no longer needed.');
                }
                abort_if($record->captured_at?->gt(now()->addMinutes(5)), 422, 'Future capture times cannot be approved.');
                $dcr = SalesDcr::query()->where('user_id', $record->user_id)->whereDate('dcr_date', $record->report_date)->first();
                if ($dcr) {
                    $dcr->assertApprovalDocumentEditable();
                    abort_unless(in_array($dcr->approval_status, ['draft', 'rejected'], true), 422, 'This DCR must be resolved through its approval workflow first.');
                }
            }
            $record->update(['status' => $data['decision'] === 'approved' && $record->recovered_at ? 'recovered' : $data['decision'], 'review_note' => $data['note'], 'reviewed_at' => now(),
                'expires_at' => $data['decision'] === 'approved' ? now()->addDays(7) : null]);

            return $record;
        });
    }

    public function canSubmitRecoveredDcr(SalesDcr $dcr): bool
    {
        $records = FieldSyncRecovery::query()->where('user_id', $dcr->user_id)->whereDate('report_date', $dcr->dcr_date)->whereNotNull('requested_at')->get();

        return $records->isNotEmpty() && $records->every(fn ($record) => $record->status === 'recovered')
            && $records->contains(fn ($record) => $record->resolution_mode !== FieldPlanConflictService::MODE && $record->expires_at?->isFuture());
    }

    public function hasPendingReview(SalesDcr $dcr): bool
    {
        return FieldSyncRecovery::query()->where('user_id', $dcr->user_id)->whereDate('report_date', $dcr->dcr_date)
            ->whereIn('status', ['pending_review', 'approved'])->exists();
    }

    public function assertNoPendingReview(SalesDcr $dcr): void
    {
        if ($this->hasPendingReview($dcr)) {
            throw ValidationException::withMessages(['field_sync_recovery' => 'Complete late sync review and synchronize the authorized original records before submitting this DCR.']);
        }
    }
}
