<?php

namespace App\Services\SalespersonAi;

use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\Lead;
use Illuminate\Support\Carbon;

class NextActionService
{
    /**
     * @return array{action: string, suggested_date: string, reason: string, summary: string}
     */
    public function forLead(Lead $lead): array
    {
        $lead->loadMissing(['status', 'followUps']);

        if ($this->isClosedName($lead->status?->name, ['converted'])) {
            return $this->payload('wait', today()->toDateString(), 'This lead is converted.');
        }

        return $this->fromFollowUps(
            $lead->followUps,
            'No follow-up is recorded for this lead.',
        );
    }

    /**
     * @return array{action: string, suggested_date: string, reason: string, summary: string}
     */
    public function forDeal(Deal $deal): array
    {
        $deal->loadMissing(['status', 'followUps']);

        if ($this->isClosedName($deal->status?->name, ['won', 'lost', 'closed'])) {
            return $this->payload('wait', today()->toDateString(), 'This deal is already closed.');
        }

        $closeDate = $deal->expected_close_date
            ? Carbon::parse($deal->expected_close_date)->startOfDay()
            : null;

        if ($closeDate && $closeDate->lte(today()->addDays(7))) {
            $reason = $closeDate->lt(today())
                ? 'The expected close date '.$closeDate->toDateString().' has passed.'
                : 'The expected close date is '.$closeDate->toDateString().'.';

            return $this->payload('visit', $closeDate->lt(today()) ? today()->toDateString() : $closeDate->toDateString(), $reason);
        }

        return $this->fromFollowUps(
            $deal->followUps,
            'No follow-up is recorded for this deal.',
        );
    }

    /**
     * @param  iterable<int, FollowUp>  $followUps
     * @return array{action: string, suggested_date: string, reason: string, summary: string}
     */
    private function fromFollowUps(iterable $followUps, string $emptyReason): array
    {
        $latest = collect($followUps)->sortByDesc('id')->first();

        if (! $latest instanceof FollowUp) {
            return $this->payload('call', today()->toDateString(), $emptyReason);
        }

        $next = $latest->next_follow_up_date
            ? Carbon::parse($latest->next_follow_up_date)->startOfDay()
            : null;

        if (! $next || $next->lt(today())) {
            $when = $next?->toDateString() ?? 'none';

            return $this->payload('call', today()->toDateString(), 'The latest follow-up date is '.$when.' and it is not still ahead.');
        }

        return $this->payload('wait', $next->toDateString(), 'A follow-up is already set for '.$next->toDateString().'.');
    }

    /**
     * @param  list<string>  $needles
     */
    private function isClosedName(?string $name, array $needles): bool
    {
        $haystack = strtolower((string) $name);

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{action: string, suggested_date: string, reason: string, summary: string}
     */
    private function payload(string $action, string $date, string $reason): array
    {
        $verb = match ($action) {
            'visit' => 'Visit',
            'wait' => 'Wait',
            default => 'Call',
        };

        return [
            'action' => $action,
            'suggested_date' => $date,
            'reason' => $reason,
            'summary' => $verb.' on '.$date.'. '.$reason,
        ];
    }
}
