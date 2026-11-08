<?php

namespace App\Services\Field;

use App\Enums\FieldPlanningMode;
use App\Models\FieldPlanningRevision;
use App\Models\FieldUserHq;
use App\Models\FlexiblePlanEntry;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FieldFlexiblePlanningService
{
    public function assertEnabled(): void
    {
        $policy = app(FieldPlanningService::class)->active();
        abort_unless($policy?->mode === FieldPlanningMode::Flexible && ($policy->rules['enable_flexible_plans'] ?? false), 422, 'Flexible planning is not enabled.');
    }

    public function findOwned(User $user, int $id): FlexiblePlanEntry
    {
        return FlexiblePlanEntry::query()->where('user_id', $user->id)->with(['account', 'patch', 'visits'])->findOrFail($id);
    }

    public function save(User $user, array $input): FlexiblePlanEntry
    {
        abort_unless($user->can('create', Visit::class), 403);
        $this->assertEnabled();
        $data = Validator::make($input, [
            'client_plan_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'account_id' => ['required', 'integer', 'min:1'], 'date' => ['required', 'date_format:Y-m-d'],
            'patch_id' => ['nullable', 'integer', 'min:1'], 'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:active,cancelled'],
        ])->validate();

        return $user->getConnection()->transaction(function () use ($user, $data): FlexiblePlanEntry {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $entry = FlexiblePlanEntry::query()->where('client_plan_id', $data['client_plan_id'])->lockForUpdate()->first();
            abort_if($entry && (int) $entry->user_id !== (int) $user->id, 404);
            $account = app(FieldAccountService::class)->findVisible($user, $data['account_id']);
            if (! empty($data['patch_id'])) {
                abort_unless($account->patches()->whereKey($data['patch_id'])->exists(), 422, 'Select a patch assigned to this customer.');
            }
            $values = ['account_master_id' => (int) $data['account_id'], 'date' => $data['date'],
                'patch_id' => isset($data['patch_id']) ? (int) $data['patch_id'] : null,
                'notes' => trim($data['notes'] ?? '') ?: null, 'status' => $data['status']];
            if ($entry && $entry->date->toDateString() === $values['date']
                && (int) $entry->account_master_id === $values['account_master_id']
                && $entry->patch_id === $values['patch_id'] && $entry->notes === $values['notes'] && $entry->status === $values['status']) {
                return $entry->load(['account', 'patch', 'visits']);
            }
            abort_unless(($entry?->version ?? 0) === (int) $data['expected_version'], 409, 'This intention changed. Refresh before editing.');
            if (($entry && $entry->date->lt(today())) || Carbon::parse($data['date'])->lt(today()) || Carbon::parse($data['date'])->gt(today()->addDays(90))) {
                throw ValidationException::withMessages(['date' => 'Plan from today through the next 90 days. Past intentions are read-only.']);
            }
            abort_if($entry?->visits()->exists(), 422, 'Execution already started. Keep this intention as history and create a new intention if needed.');
            abort_if(FlexiblePlanEntry::query()->where('user_id', $user->id)->whereDate('date', $data['date'])->where('account_master_id', $data['account_id'])
                ->when($entry, fn ($q) => $q->whereKeyNot($entry->id))->exists(), 409, 'An intention already exists for this customer and date. Refresh and edit it.');
            $history = $entry?->history ?? [];
            $history[] = ['changed_by' => $user->id, 'changed_at' => now()->toIso8601String(),
                'before' => $entry?->proposal(), 'after' => $values];
            if (! $entry) {
                $entry = new FlexiblePlanEntry(['client_plan_id' => $data['client_plan_id'], 'user_id' => $user->id]);
            }
            $entry->fill([...$values, 'version' => ($entry->version ?? 0) + 1, 'history' => $history])->save();

            return $entry->load(['account', 'patch', 'visits']);
        }, attempts: 5);
    }

    public function executable(User $user, int $id, int $version, int $accountId, string $date): FlexiblePlanEntry
    {
        $this->assertEnabled();
        $entry = FlexiblePlanEntry::query()->with('patch')->where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
        abort_unless($entry->status === 'active' && $entry->version === $version
            && (int) $entry->account_master_id === $accountId && $entry->date->toDateString() === $date,
            409, 'This intention changed or was cancelled. Your captured call is retained; refresh and reconcile it.');

        abort_if($entry->patch_id && (! $entry->patch || ! $entry->patch->companies()->whereKey($accountId)->exists()), 409, 'The customer patch changed. Refresh the intention before execution.');

        return $entry;
    }

    public function week(User $actor, string $start, ?int $userId = null): array
    {
        $userId ??= $actor->id;
        abort_unless(($userId === $actor->id && $actor->can('ViewAny:AccountMaster')) || FieldTeamOnboardingService::canManage($actor), 403);
        Validator::make(['start' => $start], ['start' => ['required', 'date_format:Y-m-d']])->validate();
        $day = Carbon::parse($start)->startOfDay();
        $end = $day->copy()->addDays(6)->endOfDay();
        $entries = FlexiblePlanEntry::query()->where('user_id', $userId)->whereBetween('date', [$day->toDateString(), $end->toDateString()])
            ->with(['account', 'patch', 'visits'])->orderBy('date')->orderBy('id')->get();
        $rows = $entries->map(fn ($entry) => $this->resource($entry))->all();
        $unplanned = Visit::query()->where('employee_id', $userId)->whereBetween('visit_date', [$day->toDateString(), $end->toDateString()])
            ->whereNull('flexible_plan_entry_id')->whereNull('sales_tour_plan_id')->whereIn('visit_status', ['started', 'checked_out_pending', 'completed'])
            ->whereIn('planning_revision_id', FieldPlanningRevision::query()->where('mode', FieldPlanningMode::Flexible->value)->select('id'))
            ->get(['id', 'visit_date', 'unplanned_reason', 'visit_status']);
        $hq = FieldUserHq::query()->with('location')->where('user_id', $userId)->first();

        return ['start' => $day->toDateString(), 'end' => $end->toDateString(), 'user_id' => $userId,
            'hq' => $hq?->location ? ['id' => $hq->location->id, 'name' => $hq->location->name] : null,
            'entries' => $rows, 'intended' => collect($rows)->where('status', 'active')->count(),
            'completed' => collect($rows)->where('execution_status', 'completed')->count(),
            'missed' => collect($rows)->where('execution_status', 'missed')->count(),
            'unplanned_visits' => $unplanned->map(fn ($v) => ['id' => $v->id, 'date' => $v->visit_date->toDateString(), 'reason' => $v->unplanned_reason, 'status' => $v->visit_status])->all()];
    }

    public function resource(FlexiblePlanEntry $entry): array
    {
        $entry->loadMissing(['account', 'patch', 'visits']);
        $completed = $entry->visits->where('visit_status', 'completed')->isNotEmpty();
        $started = $entry->visits->whereNotIn('visit_status', ['cancelled'])->isNotEmpty();

        return ['id' => $entry->id, ...$entry->proposal(), 'version' => $entry->version,
            'patch' => $entry->patch ? ['id' => $entry->patch->id, 'name' => $entry->patch->name] : null,
            'account' => $entry->account ? ['id' => $entry->account->id, 'name' => $entry->account->name,
                'geo_latitude' => $entry->account->geo_latitude, 'geo_longitude' => $entry->account->geo_longitude,
                'geo_radius_meters' => $entry->account->geo_radius_meters] : null,
            'editable' => $entry->date->gte(today()) && $entry->visits->isEmpty(),
            'execution_status' => $entry->status === 'cancelled' ? 'cancelled' : ($completed ? 'completed' : ($entry->date->lt(today()) ? 'missed' : ($started ? 'started' : 'planned')))];
    }
}
