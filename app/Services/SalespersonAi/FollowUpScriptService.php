<?php

namespace App\Services\SalespersonAi;

use App\Models\AccountMaster;
use App\Models\ContactDetail;
use App\Models\Deal;
use App\Models\FollowUp;
use Illuminate\Support\Carbon;

class FollowUpScriptService
{
    /**
     * @return array{script: string, suggested_date: string, summary: string}
     */
    public function forFollowUp(FollowUp $followUp): array
    {
        $followUp->loadMissing(['contactDetail', 'followupable']);
        $who = $this->whoName($followUp);
        $interaction = trim((string) $followUp->interaction);
        $outcome = trim((string) $followUp->outcome);

        $script = $interaction !== ''
            ? 'Hello '.$who.'. Following up on: '.$interaction.'.'
            : 'Hello '.$who.'. This is a follow-up from our last conversation.';

        if ($outcome !== '') {
            $script .= ' Last outcome: '.$outcome.'.';
        }

        $existing = $followUp->next_follow_up_date
            ? Carbon::parse($followUp->next_follow_up_date)->startOfDay()
            : null;
        $suggested = $existing && $existing->gte(today())
            ? $existing
            : today()->addDays(2);

        return [
            'script' => $script,
            'suggested_date' => $suggested->toDateString(),
            'summary' => 'Suggested date '.$suggested->toDateString().'. '.$script,
        ];
    }

    private function whoName(FollowUp $followUp): string
    {
        $contact = $followUp->contactDetail;

        if ($contact instanceof ContactDetail) {
            $name = trim(((string) $contact->first_name).' '.((string) $contact->last_name));

            if ($name !== '') {
                return $name;
            }
        }

        $parent = $followUp->followupable;

        if ($parent instanceof Deal && filled($parent->deal_name)) {
            return $parent->deal_name;
        }

        if ($parent instanceof AccountMaster && filled($parent->name)) {
            return $parent->name;
        }

        return 'there';
    }
}
