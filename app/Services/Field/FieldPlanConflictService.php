<?php

namespace App\Services\Field;

use App\Enums\FieldPlanningMode;
use App\Http\Requests\Api\V1\OpenDirectVisitRequest;
use App\Http\Resources\Api\V1\VisitResource;
use App\Models\AccountMaster;
use App\Models\FieldSyncRecovery;
use App\Models\FlexiblePlanEntry;
use App\Models\SalesDcr;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class FieldPlanConflictService
{
    public const MODE = 'accept_unplanned';

    private const TARGET_KEYS = ['client_call_id', 'company_id', 'date', 'flexible_plan_entry_id', 'flexible_plan_version', 'unplanned_reason'];

    public function eligible(FieldSyncRecovery $record): bool
    {
        return $record->path === 'api/v1/visits/direct' && ! $record->recovered_at
            && $record->captured_at && $record->report_date
            && ! empty($record->snapshot['request']['flexible_plan_entry_id'])
            && ! empty($record->snapshot['request']['flexible_plan_version']);
    }

    private function visibleAccount(User $owner, int $id): AccountMaster
    {
        $actor = Auth::user();
        try {
            Auth::setUser($owner);

            return app(FieldAccountService::class)->findVisible($owner, $id);
        } finally {
            if ($actor) {
                Auth::setUser($actor);
            }
        }
    }

    public function snapshot(FieldSyncRecovery $record, string $reason): array
    {
        abort_unless($this->eligible($record), 422, 'Only captured intention openings can request this resolution.');
        $data = Validator::make($record->snapshot['request'], (new OpenDirectVisitRequest)->rules())->validate();
        $captured = FieldRecordedAt::resolve(['recorded_at' => $record->captured_at]);
        abort_unless($captured->toDateString() === $data['date'] && $record->report_date->toDateString() === $data['date'], 422, 'Retain the original call date and capture time.');
        VisitPreference::current()->assertDcrDateIsReportable($data['date']);
        $owner = User::query()->findOrFail($record->user_id);
        abort_unless($owner->can('create', Visit::class), 403);
        $account = $this->visibleAccount($owner, (int) $data['company_id']);
        $entry = FlexiblePlanEntry::query()->where('user_id', $owner->id)->lockForUpdate()->findOrFail($data['flexible_plan_entry_id']);
        abort_unless((int) $entry->account_master_id === (int) $account->id && $entry->date->toDateString() === $data['date']
            && (int) $data['flexible_plan_version'] <= $entry->version, 422, 'The original intention must belong to this customer, representative and date.');
        abort_unless($entry->status !== 'active' || $entry->version !== (int) $data['flexible_plan_version'], 422, 'This intention is current. Retry its original opening instead.');
        abort_if(Visit::query()->where('client_call_id', $data['client_call_id'])->exists(), 409, 'This call already exists. Refresh its original receipt.');
        $policy = app(FieldPlanningService::class)->active();
        abort_unless($policy?->mode === FieldPlanningMode::Flexible, 422, 'Accept captured intention conflicts only while Flexible planning is active.');
        $candidate = new Visit(['employee_id' => $owner->id, 'visit_date' => $data['date'], 'visit_type' => 'unplanned', 'unplanned_reason' => $reason]);
        app(FieldVisitPlanningPolicy::class)->validate($candidate, $policy->rules, $reason);
        $dcr = SalesDcr::query()->where('user_id', $owner->id)->whereDate('dcr_date', $data['date'])->lockForUpdate()->first();
        if ($dcr) {
            app(FieldDcrService::class)->assertEditable($dcr);
        }

        $snapshot = ['intention' => $entry->only(['id', 'user_id', 'account_master_id', 'version', 'status', 'date', 'patch_id']),
            'customer' => $account->only(['id', 'name', 'owner_id']),
            'policy_revision' => $policy->id, 'policy_rules' => $policy->rules,
            'dcr' => $dcr?->only(['id', 'approval_status']), 'outcome' => 'Create the captured call as an unplanned visit; retain the original intention/version as permanent recovery evidence.'];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function assertUnchanged(FieldSyncRecovery $record): void
    {
        $current = $this->snapshot($record, $record->reason);
        abort_unless(Arr::sortRecursive($record->resolution_snapshot ?? []) === Arr::sortRecursive($current), 409,
            'The intention, customer, policy or DCR changed. Request a fresh review.');
    }

    public function matchesResolvedTarget(User $user, Visit $visit, array $data): bool
    {
        $record = FieldSyncRecovery::query()->where('user_id', $user->id)->where('resolved_visit_id', $visit->id)
            ->where('resolution_mode', self::MODE)->where('status', 'recovered')->whereNotNull('recovered_at')->first();

        return $record && Arr::sortRecursive(Arr::only($record->snapshot['request'], self::TARGET_KEYS))
            === Arr::sortRecursive(Arr::only($data, self::TARGET_KEYS));
    }

    public function apply(Request $request, FieldSyncRecovery $record): Response
    {
        abort_unless($record->status === 'approved' && $record->expires_at?->isFuture()
            && (int) $record->reviewer_id !== (int) $record->user_id
            && User::query()->find($record->reviewer_id)?->can('Review:FieldSyncRecovery'), 409, 'A current assigned-manager approval is required for this captured call.');
        $this->assertUnchanged($record);
        $original = $record->snapshot['request'];
        $call = Arr::only($original, ['client_call_id', 'company_id', 'date']);
        $call['unplanned_reason'] = $record->reason;
        $visit = app(FieldVisitService::class)->openDirect($request->user(), $call);
        $record->update(['resolved_visit_id' => $visit->id]);

        return (new VisitResource($visit))->additional(['sync_resolution' => ['kind' => self::MODE, 'recovery_id' => $record->id,
            'note' => 'Manager accepted the captured visit as unplanned. Original intention and evidence remain in the recovery audit.']])->toResponse($request);
    }
}
