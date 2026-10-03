<?php

namespace App\Services\SalespersonAi;

use App\Models\FollowUp;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitOutcome;
use Illuminate\Support\Carbon;

class VisitVoiceNoteService
{
    /**
     * @return array{
     *     remarks: string,
     *     visit_outcome_id: int|null,
     *     visit_outcome_label: string|null,
     *     next_follow_up_date: string|null,
     *     summary: string
     * }
     */
    public function draft(string $transcript): array
    {
        $remarks = trim($transcript);
        $outcome = $this->matchOutcome($remarks);
        $next = $this->parseFollowUpDate($remarks);

        $summary = 'Draft only. Remarks are not saved until you apply this note.';

        if ($outcome) {
            $summary .= ' Matched outcome: '.$outcome->label.'.';
        }

        if ($next) {
            $summary .= ' Suggested follow-up date: '.$next->toDateString().'.';
        }

        return [
            'remarks' => $remarks,
            'visit_outcome_id' => $outcome?->id,
            'visit_outcome_label' => $outcome?->label,
            'next_follow_up_date' => $next?->toDateString(),
            'summary' => $summary,
        ];
    }

    /**
     * @return array{visit_id: int, remarks: string, visit_outcome_id: int|null, follow_up_id: int|null, draft: array<string, mixed>}
     */
    public function apply(User $user, Visit $visit, string $transcript, bool $scheduleFollowUp): array
    {
        $draft = $this->draft($transcript);

        $visit->forceFill([
            'remarks' => $draft['remarks'],
            'visit_outcome_id' => $draft['visit_outcome_id'] ?? $visit->visit_outcome_id,
            'updated_by' => $user->id,
        ])->save();

        $followUp = null;

        if ($scheduleFollowUp && filled($draft['next_follow_up_date'])) {
            $followUp = FollowUp::query()->create([
                'followupable_type' => Visit::class,
                'followupable_id' => $visit->id,
                'user_id' => $user->id,
                'follow_up_date' => now(),
                'interaction' => $draft['remarks'],
                'next_follow_up_date' => $draft['next_follow_up_date'],
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        return [
            'visit_id' => $visit->id,
            'remarks' => (string) $visit->remarks,
            'visit_outcome_id' => $visit->visit_outcome_id,
            'follow_up_id' => $followUp?->id,
            'draft' => $draft,
        ];
    }

    private function matchOutcome(string $transcript): ?VisitOutcome
    {
        $haystack = strtolower($transcript);

        return VisitOutcome::query()
            ->get()
            ->filter(fn (VisitOutcome $outcome): bool => filled($outcome->label) && str_contains($haystack, strtolower($outcome->label)))
            ->sortByDesc(fn (VisitOutcome $outcome): int => strlen((string) $outcome->label))
            ->first();
    }

    private function parseFollowUpDate(string $transcript): ?Carbon
    {
        $text = strtolower($transcript);

        if (str_contains($text, 'tomorrow')) {
            return today()->addDay();
        }

        if (str_contains($text, 'next week')) {
            return today()->addWeek();
        }

        if (preg_match('/\bin\s+(\d{1,2})\s+days?\b/', $text, $matches) === 1) {
            return today()->addDays((int) $matches[1]);
        }

        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $transcript, $matches) === 1) {
            return Carbon::createFromFormat('Y-m-d', $matches[1]) ?: null;
        }

        return null;
    }
}
