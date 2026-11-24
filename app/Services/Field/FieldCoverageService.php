<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\FieldUserHq;
use App\Models\LocationMaster;
use App\Models\Patch;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FieldCoverageService
{
    public function forMonth(User $actor, int $userId, string $month, ?int $hqId = null): array
    {
        abort_unless($actor->can('ViewAny:AccountMaster'), 403);
        abort_unless(
            $actor->id === $userId || FieldTeamOnboardingService::canManage($actor),
            403,
        );
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => 'Choose a valid month.']);
        }
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        if ($start->isFuture()) {
            throw ValidationException::withMessages(['month' => 'Coverage is available for this month and earlier months.']);
        }
        abort_unless($actor->id === $userId || User::query()->whereKey($userId)->exists(), 404);
        $hq = FieldUserHq::query()->with('location')->where('user_id', $userId)->first();
        if ($hqId !== null && (int) $hq?->location?->id !== $hqId) {
            throw ValidationException::withMessages(['hq_id' => 'Choose a representative currently assigned to this HQ.']);
        }

        $through = $end->copy()->min(now());
        $accounts = AccountMaster::withTrashed()->whereHas('assignmentHistory', fn ($q) => $q
            ->where('owner_id', $userId)->where('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $start)))
            ->with(['assignmentHistory' => fn ($q) => $q->where('owner_id', $userId)
                ->where('effective_from', '<=', $end)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $start)),
                'callTargets' => fn ($q) => $q->where('effective_from', '<=', $end->toDateString())
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start->toDateString()))])->orderBy('name')->get();
        $visits = Visit::query()->where('employee_id', $userId)->whereBetween('visit_date', [$start->toDateString(), $through->toDateString()])
            ->where('visit_status', 'completed')->with(['salesDcr', 'visitables'])->get();
        $credited = [];
        $rows = [];
        foreach ($accounts as $account) {
            $days = [];
            $elapsed = [];
            $patchIds = [];
            foreach ($account->assignmentHistory as $assignment) {
                $from = $assignment->effective_from->copy()->max($start);
                $until = ($assignment->effective_to?->copy()->subMicrosecond() ?? $end->copy())->min($end);
                if ($account->deleted_at) {
                    $until = $until->min($account->deleted_at);
                }
                if ($from->gt($until)) {
                    continue;
                }
                foreach ($assignment->patch_ids as $patchId) {
                    $patchIds[(int) $patchId] = true;
                }
                for ($day = $from->copy()->startOfDay(); $day->lte($until); $day->addDay()) {
                    $days[$day->toDateString()] = true;
                    if ($day->lte($through)) {
                        $elapsed[$day->toDateString()] = true;
                    }
                }
            }
            if ($days === []) {
                continue;
            }
            $target = $account->callTargets->first();
            $required = $target ? (int) ceil($target->calls_per_month * count($days) / $start->daysInMonth) : null;
            $dueToDate = $target ? (int) ceil($target->calls_per_month * count($elapsed) / $start->daysInMonth) : null;
            $completed = 0;
            $pending = 0;
            $last = null;
            foreach ($visits as $visit) {
                if (! $visit->visitables->contains(fn ($link) => $link->visitable_type === AccountMaster::class && (int) $link->visitable_id === (int) $account->id)) {
                    continue;
                }
                $captured = $visit->start_time ?? $visit->visit_date->copy()->endOfDay();
                $assigned = $account->assignmentHistory->contains(fn ($a) => $captured->gte($a->effective_from)
                    && (! $a->effective_to || $captured->lt($a->effective_to)));
                if (! $assigned) {
                    continue;
                }
                if ($visit->salesDcr?->approval_status === 'approved') {
                    $completed++;
                    $credited[$visit->id] = true;
                    $last = max($last ?? '', $visit->visit_date->toDateString());
                } else {
                    $pending++;
                }
            }
            $rows[] = [
                'account_id' => $account->id, 'name' => $account->name,
                'patch_ids' => array_keys($patchIds),
                'assigned_days' => count($days), 'target_calls' => $required,
                'expected_to_date' => $dueToDate, 'completed_calls' => $completed,
                'pending_calls' => $pending, 'last_visit_date' => $last,
                'remaining_calls' => $required === null ? null : max(0, $required - $completed),
                'status' => $required === null ? 'not_configured' : ($required === 0 ? 'not_required'
                    : ($completed >= $required ? 'achieved' : ($end->isPast() ? 'missed' : ($completed < $dueToDate ? 'due' : 'on_track')))),
            ];
        }
        $eligible = collect($rows)->filter(fn ($row) => ($row['target_calls'] ?? 0) > 0);
        $covered = $eligible->filter(fn ($row) => $row['completed_calls'] > 0)->count();
        $targetCalls = $eligible->sum('target_calls');
        $achievedCalls = $eligible->sum(fn ($row) => min($row['completed_calls'], $row['target_calls']));
        $patchIds = collect($rows)->pluck('patch_ids')->flatten()->unique();
        $patchNames = Patch::withTrashed()->whereKey($patchIds->all())->pluck('name', 'id');

        return [
            'month' => $month, 'user_id' => $userId, 'as_of' => $through->toDateString(),
            'hq' => $hq?->location ? ['id' => $hq->location->id, 'name' => $hq->location->name] : null,
            'eligible_customers' => $eligible->count(), 'covered_customers' => $covered,
            'coverage_percent' => $eligible->isEmpty() ? null : round(100 * $covered / $eligible->count(), 1),
            'target_calls' => $targetCalls, 'credited_frequency_calls' => $achievedCalls,
            'frequency_percent' => $targetCalls === 0 ? null : round(100 * $achievedCalls / $targetCalls, 1),
            'unassigned_completed_calls' => $visits->filter(fn ($v) => $v->salesDcr?->approval_status === 'approved' && ! isset($credited[$v->id]))->count(),
            'joint_participations' => Visit::query()->where('employee_id', '!=', $userId)->whereBetween('visit_date', [$start->toDateString(), $through->toDateString()])
                ->where('visit_status', 'completed')->whereHas('salesDcr', fn ($q) => $q->where('approval_status', 'approved'))
                ->whereHas('jointUsers', fn ($q) => $q->where('users.id', $userId))->count(),
            'patches' => $patchIds->map(fn (int $id): array => ['id' => $id, 'name' => $patchNames->get($id) ?? 'Patch #'.$id])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'customers' => $rows,
        ];
    }

    public function hqs(User $actor): Builder
    {
        abort_unless($actor->can('ViewAny:AccountMaster'), 403);

        return LocationMaster::query()->whereIn('id', FieldUserHq::query()
            ->when(! FieldTeamOnboardingService::canManage($actor), fn (Builder $hqs): Builder => $hqs->where('user_id', $actor->id))
            ->select('location_master_id'))->orderBy('name')->orderBy('id');
    }

    public function representatives(User $actor, ?int $hqId = null): Builder
    {
        abort_unless($actor->can('ViewAny:AccountMaster'), 403);

        return User::query()
            ->when($hqId, fn (Builder $users): Builder => $users->whereIn('id', FieldUserHq::query()->where('location_master_id', $hqId)->select('user_id')))
            ->when(! FieldTeamOnboardingService::canManage($actor), fn (Builder $users): Builder => $users->whereKey($actor->id))
            ->orderBy('name')->orderBy('id');
    }

    public function teamReview(User $actor): ?array
    {
        if (! FieldTeamOnboardingService::canManage($actor)) {
            return null;
        }

        return [
            'hqs' => $this->hqs($actor)->get(['id', 'name']),
            'representatives' => $this->representatives($actor)->select(['id', 'name'])
                ->addSelect(['hq_id' => FieldUserHq::query()->whereColumn('user_id', 'users.id')->select('location_master_id')])
                ->get()->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'hq_id' => $user->hq_id]),
        ];
    }
}
