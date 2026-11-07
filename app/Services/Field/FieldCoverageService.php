<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\FieldUserHq;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FieldCoverageService
{
    public function forMonth(User $actor, int $userId, string $month): array
    {
        abort_unless(FieldTeamOnboardingService::canManage($actor)
            || ($actor->id === $userId && $actor->can('ViewAny:AccountMaster')), 403);
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => 'Choose a valid month.']);
        }
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        if ($start->isFuture()) {
            throw ValidationException::withMessages(['month' => 'Coverage is available for this month and earlier months.']);
        }
        $through = $end->copy()->min(now());
        $accounts = AccountMaster::withTrashed()->whereHas('assignmentHistory', fn ($q) => $q
            ->where('owner_id', $userId)->where('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $start)))
            ->with(['assignmentHistory' => fn ($q) => $q->where('owner_id', $userId),
                'callTargets' => fn ($q) => $q->where('effective_from', '<=', $end->toDateString())
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start->toDateString()))])->orderBy('name')->get();
        $visits = Visit::query()->where('employee_id', $userId)->whereBetween('visit_date', [$start->toDateString(), $through->toDateString()])
            ->where('visit_status', 'completed')->with(['salesDcr', 'visitables'])->get();
        $credited = [];
        $rows = [];
        foreach ($accounts as $account) {
            $days = [];
            $elapsed = [];
            foreach ($account->assignmentHistory as $assignment) {
                $from = $assignment->effective_from->copy()->max($start);
                $until = ($assignment->effective_to?->copy()->subMicrosecond() ?? $end->copy())->min($end);
                if ($account->deleted_at) {
                    $until = $until->min($account->deleted_at);
                }
                if ($from->gt($until)) {
                    continue;
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
        $hq = FieldUserHq::query()->with('location')->where('user_id', $userId)->first();

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
            'customers' => $rows,
        ];
    }
}
