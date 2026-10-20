<?php

namespace App\Services\SalespersonAi;

use App\Models\AccountMaster;
use App\Models\FollowUp;
use App\Models\Visit;
use App\Services\Reports\OverdueFollowUpQuery;
use Illuminate\Support\Carbon;

class PreCallBriefingService
{
    public function __construct(
        private SgipSuggestionService $samples,
        private AiBriefingService $ai,
    ) {}

    /**
     * @return array{
     *     account: array{id: int, name: string}|null,
     *     last_visits: list<array{id: int, visit_date: string|null, outcome: string|null, remarks: string|null}>,
     *     open_follow_ups: list<array{id: int, next_follow_up_date: string|null, interaction: string|null, overdue: bool}>,
     *     samples: array<string, mixed>,
     *     summary: string
     * }
     */
    public function forVisit(Visit $visit): array
    {
        $visit->loadMissing(['visitables.visitable']);
        $account = $visit->primaryCompany();
        $history = collect();
        $followUps = collect();

        if ($account instanceof AccountMaster) {
            $history = Visit::query()
                ->where('employee_id', $visit->employee_id)
                ->where('id', '!=', $visit->id)
                ->where('visit_status', 'completed')
                ->whereHas('visitables', function ($query) use ($account): void {
                    $query->where('visitable_type', AccountMaster::class)
                        ->where('visitable_id', $account->id);
                })
                ->with('outcome')
                ->orderByDesc('visit_date')
                ->orderByDesc('id')
                ->limit(3)
                ->get();

            $visitIds = Visit::query()
                ->where('employee_id', $visit->employee_id)
                ->whereHas('visitables', function ($query) use ($account): void {
                    $query->where('visitable_type', AccountMaster::class)
                        ->where('visitable_id', $account->id);
                })
                ->pluck('id');

            $followUps = FollowUp::query()
                ->where('user_id', $visit->employee_id)
                ->where('followupable_type', Visit::class)
                ->whereIn('followupable_id', $visitIds)
                ->where(function ($query): void {
                    $query->whereNull('follow_up_status_id')
                        ->orWhereHas('status', function ($status): void {
                            $status->whereNotIn('name', OverdueFollowUpQuery::CLOSED_STATUSES);
                        });
                })
                ->orderByDesc('id')
                ->limit(5)
                ->get();
        }

        $samplePayload = $this->samples->forVisit($visit);
        $lastVisits = $history->map(fn (Visit $past): array => [
            'id' => $past->id,
            'visit_date' => optional($past->visit_date)?->toDateString(),
            'outcome' => $past->outcome?->label,
            'remarks' => $past->remarks,
        ])->values()->all();

        $openFollowUps = $followUps->map(function (FollowUp $followUp): array {
            $next = $followUp->next_follow_up_date
                ? Carbon::parse($followUp->next_follow_up_date)->toDateString()
                : null;

            return [
                'id' => $followUp->id,
                'next_follow_up_date' => $next,
                'interaction' => $followUp->interaction,
                'overdue' => $next !== null && Carbon::parse($next)->lt(today()),
            ];
        })->values()->all();

        return $this->ai->enrich($visit, [
            'account' => $account instanceof AccountMaster ? [
                'id' => $account->id,
                'name' => $account->name,
            ] : null,
            'last_visits' => $lastVisits,
            'open_follow_ups' => $openFollowUps,
            'samples' => $samplePayload,
            'summary' => $this->summary($account instanceof AccountMaster ? $account : null, $lastVisits, $openFollowUps, $samplePayload),
        ]);
    }

    /**
     * @param  list<array{id: int, visit_date: string|null, outcome: string|null, remarks: string|null}>  $lastVisits
     * @param  list<array{id: int, next_follow_up_date: string|null, interaction: string|null, overdue: bool}>  $openFollowUps
     * @param  array<string, mixed>  $samples
     */
    private function summary(?AccountMaster $account, array $lastVisits, array $openFollowUps, array $samples): string
    {
        $parts = [];

        if ($account) {
            $parts[] = $account->name.'.';
        } else {
            $parts[] = 'This visit has no linked account.';
        }

        if ($lastVisits === []) {
            $parts[] = 'No completed visits are recorded for this account.';
        } else {
            $last = $lastVisits[0];
            $sentence = 'Last completed visit was '.($last['visit_date'] ?? 'on an unrecorded date');

            if (filled($last['outcome'])) {
                $sentence .= ' with outcome '.$last['outcome'];
            }

            $parts[] = $sentence.'.';

            if (filled($last['remarks'])) {
                $parts[] = 'Last remarks: '.$last['remarks'];
            }
        }

        $overdue = collect($openFollowUps)->where('overdue', true)->count();

        if ($overdue > 0) {
            $parts[] = $overdue.' follow-up'.($overdue === 1 ? ' is' : 's are').' overdue.';
        }

        if (filled($samples['summary'] ?? null) && ($samples['suggestions'] !== [] || $samples['warnings'] !== [])) {
            $parts[] = (string) $samples['summary'];
        }

        return implode(' ', $parts);
    }
}
