<?php

namespace App\Enums;

enum SalespersonAiFeature: string
{
    case PreCallBriefing = 'pre_call_briefing';
    case VoiceNote = 'voice_note';
    case TodayPriorities = 'today_priorities';
    case TourPlanDraft = 'tour_plan_draft';
    case SgipSuggestion = 'sgip_suggestion';
    case FollowUpScript = 'follow_up_script';
    case DayRoute = 'day_route';
    case NextAction = 'next_action';

    public function label(): string
    {
        return match ($this) {
            self::PreCallBriefing => 'Pre-call briefing',
            self::VoiceNote => 'Voice note',
            self::TodayPriorities => 'Today’s priority list',
            self::TourPlanDraft => 'Next month tour-plan draft',
            self::SgipSuggestion => 'Sample suggestion',
            self::FollowUpScript => 'Follow-up script',
            self::DayRoute => 'Day route order',
            self::NextAction => 'Lead and deal next action',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::PreCallBriefing => 'Last visits, remarks, open follow-ups, and remaining samples for this account.',
            self::VoiceNote => 'Turn a spoken or typed call note into remarks, an outcome, and an optional follow-up. Nothing is saved until you apply it.',
            self::TodayPriorities => 'Overdue follow-ups, today’s unfinished calls, and accounts still missed on this month’s plan.',
            self::TourPlanDraft => 'A starting month plan copied from the previous month, plus accounts that were planned and not completed.',
            self::SgipSuggestion => 'A sample quantity that stays inside the recorded SGIP limit.',
            self::FollowUpScript => 'A short follow-up line taken from the last interaction, plus a suggested date.',
            self::DayRoute => 'Today’s planned accounts ordered by nearest stop when coordinates exist.',
            self::NextAction => 'Call, visit, or wait, based on the follow-up and the expected close date.',
        };
    }

    public function whereToUse(): string
    {
        return match ($this) {
            self::PreCallBriefing => 'Open a visit. The briefing sits above the form.',
            self::VoiceNote => 'On a visit, use Voice note, then Apply note.',
            self::TodayPriorities => 'Sales Assistant shows the list for today.',
            self::TourPlanDraft => 'Sales Assistant shows a draft for next month. Create the tour plan yourself.',
            self::SgipSuggestion => 'The visit briefing includes the sample suggestion.',
            self::FollowUpScript => 'Open a follow-up. The script is under the title.',
            self::DayRoute => 'Sales Assistant shows the stop order. The approved plan is unchanged.',
            self::NextAction => 'Open a lead or a deal. The next action is under the title.',
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::PreCallBriefing => 1,
            self::VoiceNote => 2,
            self::TodayPriorities => 3,
            self::TourPlanDraft => 4,
            self::SgipSuggestion => 5,
            self::FollowUpScript => 6,
            self::DayRoute => 7,
            self::NextAction => 8,
        };
    }

    public function status(): string
    {
        return 'available';
    }

    /**
     * @return list<array{key: string, order: int, label: string, summary: string, where_to_use: string, status: string}>
     */
    public static function roadmap(): array
    {
        return collect(self::cases())
            ->sortBy(fn (self $feature): int => $feature->order())
            ->map(fn (self $feature): array => [
                'key' => $feature->value,
                'order' => $feature->order(),
                'label' => $feature->label(),
                'summary' => $feature->summary(),
                'where_to_use' => $feature->whereToUse(),
                'status' => $feature->status(),
            ])
            ->values()
            ->all();
    }
}
