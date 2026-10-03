<?php

namespace App\Services\SalespersonAi;

use App\Models\AccountMaster;
use App\Models\FollowUp;
use App\Models\SalesTourPlanDetail;
use App\Models\User;
use App\Models\Visit;
use App\Services\Reports\OverdueFollowUpQuery;
use Illuminate\Support\Carbon;

class TodayPriorityService
{
    /**
     * @return array{date: string, items: list<array<string, mixed>>, summary: string}
     */
    public function forUser(User $user, Carbon $date): array
    {
        $items = [];

        foreach ($this->overdueFollowUps($user) as $followUp) {
            $when = $followUp->next_follow_up_date
                ? Carbon::parse($followUp->next_follow_up_date)->toDateString()
                : Carbon::parse($followUp->follow_up_date)->toDateString();
            $note = trim((string) $followUp->interaction);

            $items[] = [
                'kind' => 'overdue_follow_up',
                'title' => $this->followUpTitle($followUp),
                'reason' => 'Follow-up date '.$when.' has passed.'.($note !== '' ? ' Last note: '.$this->excerpt($note) : ''),
                'account_id' => null,
                'follow_up_id' => $followUp->id,
                'visit_id' => $followUp->followupable_type === Visit::class ? $followUp->followupable_id : null,
            ];
        }

        $planned = $this->plannedAccounts($user, $date, $date);
        $completedToday = $this->completedAccountIds($user, $date, $date);
        $plannedIds = [];

        foreach ($planned as $account) {
            $plannedIds[] = $account['id'];

            if (in_array($account['id'], $completedToday, true)) {
                continue;
            }

            $items[] = [
                'kind' => 'planned_today',
                'title' => $account['name'],
                'reason' => 'Planned for '.$date->toDateString().' on '.$account['patch_name'].' and not completed.',
                'account_id' => $account['id'],
                'follow_up_id' => null,
                'visit_id' => null,
            ];
        }

        $monthStart = $date->copy()->startOfMonth();
        $monthEnd = $date->copy()->endOfMonth();
        $completedThisMonth = $this->completedAccountIds($user, $monthStart, $monthEnd);

        foreach ($this->plannedAccounts($user, $monthStart, $monthEnd) as $account) {
            if (in_array($account['id'], $plannedIds, true) || in_array($account['id'], $completedThisMonth, true)) {
                continue;
            }

            $items[] = [
                'kind' => 'coverage_gap',
                'title' => $account['name'],
                'reason' => 'On this month’s tour plan with no completed visit.',
                'account_id' => $account['id'],
                'follow_up_id' => null,
                'visit_id' => null,
            ];
        }

        $items = array_slice($items, 0, 20);
        $count = count($items);

        return [
            'date' => $date->toDateString(),
            'items' => $items,
            'summary' => $count === 0
                ? 'Nothing is overdue, unfinished, or missed for '.$date->toDateString().'.'
                : $count.' item'.($count === 1 ? '' : 's').' to handle for '.$date->toDateString().'.',
        ];
    }

    /**
     * @return list<FollowUp>
     */
    private function overdueFollowUps(User $user): array
    {
        return app(OverdueFollowUpQuery::class)
            ->builder()
            ->where('user_id', $user->id)
            ->with('contactDetail')
            ->orderBy('next_follow_up_date')
            ->limit(10)
            ->get()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, patch_name: string}>
     */
    private function plannedAccounts(User $user, Carbon $from, Carbon $to): array
    {
        $details = SalesTourPlanDetail::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('tourPlan', fn ($query) => $query->where('user_id', $user->id))
            ->with('patches.companies')
            ->get();

        $accounts = [];

        foreach ($details as $detail) {
            foreach ($detail->patches as $patch) {
                foreach ($patch->companies as $company) {
                    if (isset($accounts[$company->id])) {
                        continue;
                    }

                    $accounts[$company->id] = [
                        'id' => $company->id,
                        'name' => $company->name,
                        'patch_name' => $patch->name,
                    ];
                }
            }
        }

        return array_values($accounts);
    }

    /**
     * @return list<int>
     */
    private function completedAccountIds(User $user, Carbon $from, Carbon $to): array
    {
        return Visit::query()
            ->where('employee_id', $user->id)
            ->where('visit_status', 'completed')
            ->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])
            ->with('visitables')
            ->get()
            ->flatMap(fn (Visit $visit) => $visit->visitables
                ->where('visitable_type', AccountMaster::class)
                ->pluck('visitable_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function followUpTitle(FollowUp $followUp): string
    {
        $contact = $followUp->contactDetail;
        $name = trim(((string) $contact?->first_name).' '.((string) $contact?->last_name));

        return $name !== '' ? $name : 'Follow-up';
    }

    private function excerpt(string $note): string
    {
        if (strlen($note) <= 160) {
            return $note;
        }

        return substr($note, 0, 157).'...';
    }
}
